<?php

namespace Pixelpillow\LunarApiMollieAdapter;

use Dystcz\LunarApi\Domain\Orders\Events\OrderPaymentCanceled;
use Dystcz\LunarApi\Domain\Orders\Events\OrderPaymentFailed;
use Dystcz\LunarApi\Domain\Payments\PaymentAdapters\PaymentAdapter;
use Dystcz\LunarApi\Domain\Payments\PaymentAdapters\PaymentIntent;
use Dystcz\LunarApi\Domain\Transactions\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Lunar\Models\Cart;
use Mollie\Api\Exceptions\ApiException;
use Mollie\Api\Types\PaymentMethod;
use Mollie\Laravel\Facades\Mollie;
use Pixelpillow\LunarApiMollieAdapter\Actions\AuthorizeMolliePayment;
use Pixelpillow\LunarApiMollieAdapter\Exceptions\MissingMetadataException;
use Pixelpillow\LunarApiMollieAdapter\Managers\MollieManager;
use Throwable;

class MolliePaymentAdapter extends PaymentAdapter
{
    protected Cart $cart;

    protected string $type = 'mollie';

    protected MollieManager $mollie;

    public function __construct()
    {
        $this->mollie = app(MollieManager::class);
    }

    public function getDriver(): string
    {
        return Config::get('lunar-api.mollie.driver', 'mollie');
    }

    public function getType(): string
    {
        return $this->type;
    }

    /**
     * Set cart.
     */
    protected function setCart(Cart $cart): void
    {
        $this->cart = $cart;
    }

    /**
     * Set type
     */
    protected function setType(string $type): void
    {
        $this->type = $type;
    }

    public function createIntent(Cart $cart, array $meta = [], ?int $amount = null): PaymentIntent
    {
        $this->setCart($cart);

        $paymentMethodType = $this->validatePaymentMethodType($meta['payment_method_type'] ?? null);

        if ($paymentMethodType === PaymentMethod::IDEAL) {
            $paymentMethodIssuer = $this->validatePaymentIssuer($meta['payment_method_issuer'] ?? null);
        }

        try {
            $amount = $amount ?? null;
            $description = "Lunar web payment for cart #{$cart->id}";
            $molliePayment = $this->mollie->createPayment($cart->calculate(), $paymentMethodType, $paymentMethodIssuer ?? null, $description, $amount);
        } catch (Throwable $e) {
            throw new ApiException('Mollie payment failed: '.$e->getMessage());
        }

        $paymentIntent = new PaymentIntent(
            id: $molliePayment->id,
            status: $molliePayment->status,
            amount: MollieManager::normalizeAmountToInteger($molliePayment->amount->value),
            meta: [
                'mollie_checkout_url' => $molliePayment->getCheckoutUrl(),
            ]
        );

        $transactionMeta = [
            'payment_method' => $paymentMethodType,
            'mollie_checkout_url' => $molliePayment->getCheckoutUrl(),
        ];

        $this->setType($paymentMethodType);

        if ($paymentMethodType === PaymentMethod::IDEAL) {
            $transactionMeta['payment_method_issuer'] = $paymentMethodIssuer;
        }

        $this->createTransaction($cart, $paymentIntent, [
            'meta' => $transactionMeta,
            'type' => 'intent',
        ]);

        return $paymentIntent;
    }

    /**
     * Validate the payment methose type against the Mollie payment method types
     *
     * @param  string|null  $paymentMethodType  The payment method type eg. ideal
     */
    public function validatePaymentMethodType(?string $paymentMethodType): string
    {
        if (! $paymentMethodType) {
            throw new MissingMetadataException('Payment method type is required');
        }

        if (! defined('Mollie\Api\Types\PaymentMethod::'.strtoupper($paymentMethodType))) {
            throw new MissingMetadataException('Payment method type is not a valid Mollie payment method type');
        }

        return $paymentMethodType;
    }

    /**
     * Validate the payment issuer against the Mollie payment issuers
     *
     * @param  string|null  $paymentIssuer  The payment issuer eg. ideal_ABNANL2A
     */
    public function validatePaymentIssuer(?string $paymentIssuer): ?string
    {
        if (! $paymentIssuer) {
            throw new MissingMetadataException('Payment issuer is required');
        }

        return $paymentIssuer;
    }

    public function handleWebhook(Request $request): JsonResponse
    {
        $paymentId = $request->get('id');

        if (! $paymentId) {
            return response()->json(['error' => 'Payment id is required'], 400);
        }

        $payment = $this->mollie->getPayment($paymentId);

        if (! $payment) {
            return response()->json(['error' => 'Payment not found'], 404);
        }

        $transaction = Transaction::where('reference', $payment->id)->first();

        try {
            $order = $transaction->order;
        } catch (Throwable $e) {
            return new JsonResponse([
                'webhook_successful' => false,
                'message' => "Order not found for transaction #{$transaction->id}",
            ], 404);
        }

        $paymentIntent = new PaymentIntent(
            id: $transaction->reference,
            status: $payment->status,
            amount: MollieManager::normalizeAmountToInteger($payment->amount->value),
            meta: [
                'mollie_checkout_url' => $payment->getCheckoutUrl(),
            ]
        );

        $this->setCart($order->cart);

        if ($payment->isPaid() && $transaction->status !== 'paid') {
            App::make(AuthorizeMolliePayment::class)($order, $paymentIntent, $transaction);

            $this->setTransactionStatus($transaction, 'paid');

            return response()->json(['message' => 'success']);
        }

        if ($payment->isCanceled()) {
            OrderPaymentCanceled::dispatch($order, $this, $paymentIntent);

            $this->setTransactionStatus($transaction, 'cancelled');

            return response()->json(['message' => 'cancelled']);
        }

        if ($payment->isFailed()) {
            OrderPaymentFailed::dispatch($order, $this, $paymentIntent);

            $this->setTransactionStatus($transaction, 'failed');

            return response()->json(['message' => 'failed']);
        }

        if ($payment->isExpired()) {
            OrderPaymentFailed::dispatch($order, $this, $paymentIntent);

            $this->setTransactionStatus($transaction, 'expired');

            return response()->json(['message' => 'expired']);
        }

        return response()->json(['message' => 'unknown event']);
    }

    public function setTransactionStatus(Transaction $transaction, string $status)
    {
        $transaction->update([
            'status' => $status,
        ]);
    }

    /**
     * Initialize the Mollie manager with the Mollie key
     */
    public static function initMollieManager(): void
    {
        $mollieKey = Config::get('lunar-api.mollie.mollie_key');

        Config::set('mollie.key', $mollieKey);
    }
}
