<?php

namespace Wexo\Webshipper\Plugin\Sales\Block\Adminhtml\Order;

use Magento\Sales\Block\Adminhtml\Order\View as OrderView;

class ExportOrderButton
{
    /**
     * @var \Wexo\Webshipper\Model\Config
     */
    private $config;

    public function __construct(
        \Wexo\Webshipper\Model\Config $config
    ) {
        $this->config = $config;
    }

    public function beforeSetLayout(OrderView $subject)
    {
        if (!$this->config->showButtonOnOrder()) {
            return;
        }
        $order = $subject->getOrder();
        $shippingMethod = $order->getShippingMethod();
        if(strpos($shippingMethod ?? '', 'webshipper') === false) {
            return;
        }

        $subject->addButton(
            'webshipper_order_export',
            [
                'label' => __('Export to Webshipper'),
                'class' => __('webshipper-order-export'),
                'id' => 'webshipper-order-export',
                'onclick' => 'setLocation(\'' .
                    $subject->getUrl('wexo_webshipper/order/export') .
                    '\')'
            ]
        );
    }
}
