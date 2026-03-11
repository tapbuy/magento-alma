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
                    $originalReturnUrl = $resultPayment['return_url'] ?? null;
                    
                    if (isset($tapbuyAdditionalInfo['accept_url'])) {
                        $resultPayment['return_url'] = $tapbuyAdditionalInfo['accept_url'];
                    }
                    if (isset($tapbuyAdditionalInfo['cancel_url'])) {
                        $resultPayment['customer_cancel_url'] = $tapbuyAdditionalInfo['cancel_url'];
                        $resultPayment['failure_return_url'] = $tapbuyAdditionalInfo['cancel_url'];
                    }
                    $result['payment'] = $resultPayment;
                    
                    $this->logger->info('Alma payment URLs modified for Tapbuy call', [
                        'original_return_url' => $originalReturnUrl,
                        'tapbuy_return_url' => $resultPayment['return_url'] ?? null,
                        'tapbuy_cancel_url' => $resultPayment['customer_cancel_url'] ?? null,
                    ]);
                }
            } catch (\Exception $e) {
                $this->logger->logException('Failed to process Tapbuy additional info for Alma payment', $e);
            }
        }

        return $result;
    }
}
