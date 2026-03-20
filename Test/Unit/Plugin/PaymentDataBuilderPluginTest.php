<?php

declare(strict_types=1);

namespace Tapbuy\Alma\Test\Unit\Plugin;

use Alma\MonthlyPayments\Gateway\Request\PaymentDataBuilder;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tapbuy\Alma\Plugin\PaymentDataBuilderPlugin;
use Tapbuy\RedirectTracking\Api\ConfigInterface;
use Tapbuy\RedirectTracking\Api\LoggerInterface;
use Tapbuy\RedirectTracking\Api\TapbuyConstants;
use Tapbuy\RedirectTracking\Api\TapbuyRequestDetectorInterface;
use Magento\Framework\Serialize\SerializerInterface;

class PaymentDataBuilderPluginTest extends TestCase
{
    private PaymentDataBuilderPlugin $plugin;
    private SerializerInterface&MockObject $serializer;
    private LoggerInterface&MockObject $logger;
    private TapbuyRequestDetectorInterface&MockObject $requestDetector;
    private ConfigInterface&MockObject $config;
    private PaymentDataBuilder&MockObject $subject;

    protected function setUp(): void
    {
        $this->serializer = $this->createMock(SerializerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->requestDetector = $this->createMock(TapbuyRequestDetectorInterface::class);
        $this->config = $this->createMock(ConfigInterface::class);
        $this->subject = $this->createMock(PaymentDataBuilder::class);

        $this->plugin = new PaymentDataBuilderPlugin(
            $this->serializer,
            $this->logger,
            $this->requestDetector,
            $this->config
        );
    }

    public function testAfterBuildReturnsUnmodifiedResultWhenDisabled(): void
    {
        $this->config->method('isEnabled')->willReturn(false);
        $this->requestDetector->method('isTapbuyCall')->willReturn(true);

        $result = ['payment' => ['return_url' => 'https://original.com/return']];
        $buildSubject = $this->createBuildSubject();

        $this->assertSame($result, $this->plugin->afterBuild($this->subject, $result, $buildSubject));
    }

    public function testAfterBuildReturnsUnmodifiedResultWhenNotTapbuyCall(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->requestDetector->method('isTapbuyCall')->willReturn(false);

        $result = ['payment' => ['return_url' => 'https://original.com/return']];
        $buildSubject = $this->createBuildSubject();

        $this->assertSame($result, $this->plugin->afterBuild($this->subject, $result, $buildSubject));
    }

    public function testAfterBuildReturnsUnmodifiedResultWhenNoAdditionalInfo(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->requestDetector->method('isTapbuyCall')->willReturn(true);

        $result = ['payment' => ['return_url' => 'https://original.com/return']];
        $buildSubject = $this->createBuildSubject(null);

        $this->assertSame($result, $this->plugin->afterBuild($this->subject, $result, $buildSubject));
    }

    public function testAfterBuildMapsAcceptUrlToReturnUrl(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->requestDetector->method('isTapbuyCall')->willReturn(true);

        $tapbuyInfo = ['accept_url' => 'https://tapbuy.io/accept'];
        $serialized = json_encode($tapbuyInfo);

        $this->serializer->method('unserialize')
            ->with($serialized)
            ->willReturn($tapbuyInfo);

        $result = ['payment' => ['return_url' => 'https://original.com/return']];
        $buildSubject = $this->createBuildSubject($serialized);

        $modified = $this->plugin->afterBuild($this->subject, $result, $buildSubject);

        $this->assertSame('https://tapbuy.io/accept', $modified['payment']['return_url']);
    }

    public function testAfterBuildMapsCancelUrlToMultipleAlmaFields(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->requestDetector->method('isTapbuyCall')->willReturn(true);

        $tapbuyInfo = ['cancel_url' => 'https://tapbuy.io/cancel'];
        $serialized = json_encode($tapbuyInfo);

        $this->serializer->method('unserialize')
            ->with($serialized)
            ->willReturn($tapbuyInfo);

        $result = ['payment' => [
            'customer_cancel_url' => 'https://original.com/cancel',
            'failure_return_url' => 'https://original.com/fail',
        ]];
        $buildSubject = $this->createBuildSubject($serialized);

        $modified = $this->plugin->afterBuild($this->subject, $result, $buildSubject);

        $this->assertSame('https://tapbuy.io/cancel', $modified['payment']['customer_cancel_url']);
        $this->assertSame('https://tapbuy.io/cancel', $modified['payment']['failure_return_url']);
    }

    public function testAfterBuildMapsBothAcceptAndCancelUrls(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->requestDetector->method('isTapbuyCall')->willReturn(true);

        $tapbuyInfo = [
            'accept_url' => 'https://tapbuy.io/accept',
            'cancel_url' => 'https://tapbuy.io/cancel',
        ];
        $serialized = json_encode($tapbuyInfo);

        $this->serializer->method('unserialize')
            ->with($serialized)
            ->willReturn($tapbuyInfo);

        $result = ['payment' => [
            'return_url' => 'https://original.com/return',
            'customer_cancel_url' => 'https://original.com/cancel',
            'failure_return_url' => 'https://original.com/fail',
        ]];
        $buildSubject = $this->createBuildSubject($serialized);

        $modified = $this->plugin->afterBuild($this->subject, $result, $buildSubject);

        $this->assertSame('https://tapbuy.io/accept', $modified['payment']['return_url']);
        $this->assertSame('https://tapbuy.io/cancel', $modified['payment']['customer_cancel_url']);
        $this->assertSame('https://tapbuy.io/cancel', $modified['payment']['failure_return_url']);
    }

    public function testAfterBuildLogsUrlModifications(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->requestDetector->method('isTapbuyCall')->willReturn(true);

        $tapbuyInfo = ['accept_url' => 'https://tapbuy.io/accept'];
        $serialized = json_encode($tapbuyInfo);

        $this->serializer->method('unserialize')->willReturn($tapbuyInfo);

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                'Alma payment URLs modified for Tapbuy call',
                $this->callback(function (array $context) {
                    return array_key_exists('original_return_url', $context)
                        && array_key_exists('tapbuy_return_url', $context);
                })
            );

        $result = ['payment' => ['return_url' => 'https://original.com/return']];
        $buildSubject = $this->createBuildSubject($serialized);

        $this->plugin->afterBuild($this->subject, $result, $buildSubject);
    }

    public function testAfterBuildHandlesInvalidArgumentException(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->requestDetector->method('isTapbuyCall')->willReturn(true);

        $this->serializer->method('unserialize')
            ->willThrowException(new \InvalidArgumentException('Invalid JSON'));

        $this->logger->expects($this->once())->method('logException');

        $result = ['payment' => ['return_url' => 'https://original.com/return']];
        $buildSubject = $this->createBuildSubject('invalid-json');

        $modified = $this->plugin->afterBuild($this->subject, $result, $buildSubject);

        $this->assertSame($result, $modified);
    }

    public function testAfterBuildHandlesRuntimeException(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->requestDetector->method('isTapbuyCall')->willReturn(true);

        $this->serializer->method('unserialize')
            ->willThrowException(new \RuntimeException('Serialization error'));

        $this->logger->expects($this->once())->method('logException');

        $result = ['payment' => ['return_url' => 'https://original.com/return']];
        $buildSubject = $this->createBuildSubject('bad-data');

        $modified = $this->plugin->afterBuild($this->subject, $result, $buildSubject);

        $this->assertSame($result, $modified);
    }

    public function testAfterBuildSkipsMappingWhenPaymentArrayIsEmpty(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->requestDetector->method('isTapbuyCall')->willReturn(true);

        $tapbuyInfo = ['accept_url' => 'https://tapbuy.io/accept'];
        $serialized = json_encode($tapbuyInfo);

        $this->serializer->method('unserialize')->willReturn($tapbuyInfo);

        $result = ['payment' => []];
        $buildSubject = $this->createBuildSubject($serialized);

        $modified = $this->plugin->afterBuild($this->subject, $result, $buildSubject);

        // Empty payment array is falsy, so mapping is skipped
        $this->assertSame([], $modified['payment']);
    }

    public function testAfterBuildSkipsMappingWhenDeserializedDataIsNotArray(): void
    {
        $this->config->method('isEnabled')->willReturn(true);
        $this->requestDetector->method('isTapbuyCall')->willReturn(true);

        $serialized = '"just a string"';
        $this->serializer->method('unserialize')->willReturn('just a string');

        $result = ['payment' => ['return_url' => 'https://original.com/return']];
        $buildSubject = $this->createBuildSubject($serialized);

        $modified = $this->plugin->afterBuild($this->subject, $result, $buildSubject);

        $this->assertSame('https://original.com/return', $modified['payment']['return_url']);
    }

    /**
     * @param string|null $additionalInfo
     * @return array
     */
    private function createBuildSubject(?string $additionalInfo = 'some-info'): array
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getAdditionalInformation')
            ->with(TapbuyConstants::PAYMENT_ADDITIONAL_INFO_KEY)
            ->willReturn($additionalInfo);

        $paymentDO = $this->createMock(PaymentDataObjectInterface::class);
        $paymentDO->method('getPayment')->willReturn($payment);

        return ['payment' => $paymentDO];
    }
}
