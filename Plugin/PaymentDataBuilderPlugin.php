<?php

namespace Tapbuy\Alma\Plugin;

use Alma\MonthlyPayments\Gateway\Request\PaymentDataBuilder;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Framework\Serialize\SerializerInterface;

class PaymentDataBuilderPlugin
{
    /**
     * @var SerializerInterface
     */
    private $serializer;

    public function __construct(SerializerInterface $serializer)
    {
        $this->serializer = $serializer;
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
        $paymentDO = SubjectReader::readPayment($buildSubject);
        $payment = $paymentDO->getPayment();

        $tapbuyAdditionalInfoRaw = $payment->getAdditionalInformation('tapbuy');

        if (!empty($tapbuyAdditionalInfoRaw)) {
            try {
                $tapbuyAdditionalInfo = $this->serializer->unserialize($tapbuyAdditionalInfoRaw);
                
                $resultPayment = $result['payment'];
                if (!empty($resultPayment) && is_array($tapbuyAdditionalInfo)) {
                    if (isset($tapbuyAdditionalInfo['accept_url'])) {
                        $resultPayment['return_url'] = $tapbuyAdditionalInfo['accept_url'];
                    }
                    if (isset($tapbuyAdditionalInfo['cancel_url'])) {
                        $resultPayment['customer_cancel_url'] = $tapbuyAdditionalInfo['cancel_url'];
                        $resultPayment['failure_return_url'] = $tapbuyAdditionalInfo['cancel_url'];
                    }
                    $result['payment'] = $resultPayment;
                }
            } catch (\Exception $e) {
                // Do nothing if unserialization fails
                // This ensures that the plugin does not break the payment process
            }
        }
        
        return $result;
    }
}