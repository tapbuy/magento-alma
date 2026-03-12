<?php

declare(strict_types=1);

namespace Tapbuy\Alma\Plugin;

use Alma\MonthlyPayments\Gateway\Request\PaymentDataBuilder;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Framework\Serialize\SerializerInterface;
use Tapbuy\RedirectTracking\Api\ConfigInterface;
use Tapbuy\RedirectTracking\Api\LoggerInterface;
use Tapbuy\RedirectTracking\Api\TapbuyConstants;
use Tapbuy\RedirectTracking\Api\TapbuyRequestDetectorInterface;

class PaymentDataBuilderPlugin
{
    /**
     * Maps TapBuy additional-info keys to the Alma payment fields they populate.
     * Add a new entry here to support additional URL redirections without modifying plugin logic.
     *
     * @var array<string, string[]>
     */
    private const URL_MAPPING = [
        'accept_url' => ['return_url'],
        'cancel_url' => ['customer_cancel_url', 'failure_return_url'],
    ];

    /**
     * @var SerializerInterface
     */
    private $serializer;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var TapbuyRequestDetectorInterface
     */
    private $requestDetector;

    /**
     * @var ConfigInterface
     */
    private $config;

    /**
     * @param SerializerInterface $serializer
     * @param LoggerInterface $logger
     * @param TapbuyRequestDetectorInterface $requestDetector
     * @param ConfigInterface $config
     */
    public function __construct(
        SerializerInterface $serializer,
        LoggerInterface $logger,
        TapbuyRequestDetectorInterface $requestDetector,
        ConfigInterface $config
    ) {
        $this->serializer = $serializer;
        $this->logger = $logger;
        $this->requestDetector = $requestDetector;
        $this->config = $config;
    }

    /**
     * Plugin to modify payment data after PaymentDataBuilder build
     *
     * @param PaymentDataBuilder $subject
     * @param array $result
     * @param array $buildSubject
     * @return array
     */
    public function afterBuild(PaymentDataBuilder $subject, array $result, array $buildSubject): array
    {
        if (!$this->config->isEnabled() || !$this->requestDetector->isTapbuyCall()) {
            return $result;
        }

        $paymentDO = SubjectReader::readPayment($buildSubject);
        $payment = $paymentDO->getPayment();

        $tapbuyAdditionalInfoRaw = $payment->getAdditionalInformation(TapbuyConstants::PAYMENT_ADDITIONAL_INFO_KEY);

        if (!empty($tapbuyAdditionalInfoRaw)) {
            try {
                $tapbuyAdditionalInfo = $this->serializer->unserialize($tapbuyAdditionalInfoRaw);

                $resultPayment = $result['payment'];
                if (!empty($resultPayment) && is_array($tapbuyAdditionalInfo)) {
                    $originalPayment = $resultPayment;
                    $resultPayment = $this->applyUrlMapping($tapbuyAdditionalInfo, $resultPayment);
                    $result['payment'] = $resultPayment;

                    $logContext = $this->buildLogContext($tapbuyAdditionalInfo, $originalPayment, $resultPayment);
                    if (!empty($logContext)) {
                        $this->logger->info('Alma payment URLs modified for Tapbuy call', $logContext);
                    }
                }
            } catch (\Exception $e) {
                $this->logger->logException('Failed to process Tapbuy additional info for Alma payment', $e);
            }
        }

        return $result;
    }

    /**
     * Applies URL_MAPPING to override Alma payment fields with TapBuy redirect URLs.
     *
     * @param array $tapbuyInfo Deserialized TapBuy additional information
     * @param array $payment    Alma payment fields array
     * @return array            Modified payment fields array
     */
    private function applyUrlMapping(array $tapbuyInfo, array $payment): array
    {
        foreach (self::URL_MAPPING as $tapbuyKey => $almaFields) {
            if (isset($tapbuyInfo[$tapbuyKey])) {
                foreach ($almaFields as $almaField) {
                    $payment[$almaField] = $tapbuyInfo[$tapbuyKey];
                }
            }
        }
        return $payment;
    }

    /**
     * Builds log context by comparing original and new payment fields for all mapped URLs.
     *
     * @param array $tapbuyInfo      Deserialized TapBuy additional information
     * @param array $originalPayment Alma payment fields before mapping
     * @param array $newPayment      Alma payment fields after mapping
     * @return array                 Log context array
     */
    private function buildLogContext(array $tapbuyInfo, array $originalPayment, array $newPayment): array
    {
        $context = [];
        foreach (self::URL_MAPPING as $tapbuyKey => $almaFields) {
            if (isset($tapbuyInfo[$tapbuyKey])) {
                foreach ($almaFields as $almaField) {
                    $context['original_' . $almaField] = $originalPayment[$almaField] ?? null;
                    $context['tapbuy_' . $almaField] = $newPayment[$almaField] ?? null;
                }
            }
        }
        return $context;
    }
}
