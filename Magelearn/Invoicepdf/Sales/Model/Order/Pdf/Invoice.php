<?php
declare(strict_types=1);
namespace Magelearn\Invoicepdf\Sales\Model\Order\Pdf;

use Magento\Framework\DataObject;
use Magento\Sales\Model\Order\Pdf\Config;
use Magento\Framework\App\ObjectManager;
use Magento\Sales\Model\RtlTextHandler;
use Magento\Sales\Model\Order\Pdf\Invoice as InvoicePdf;

class Invoice extends InvoicePdf
{
    protected $_productRepositoryFactory;
    
    public function __construct(
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Framework\Stdlib\StringUtils $string,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Framework\Filesystem $filesystem,
        Config $pdfConfig,
        \Magento\Sales\Model\Order\Pdf\Total\Factory $pdfTotalFactory,
        \Magento\Sales\Model\Order\Pdf\ItemsFactory $pdfItemsFactory,
        \Magento\Framework\Stdlib\DateTime\TimezoneInterface $localeDate,
        \Magento\Framework\Translate\Inline\StateInterface $inlineTranslation,
        \Magento\Sales\Model\Order\Address\Renderer $addressRenderer,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Store\Model\App\Emulation $appEmulation,
        \Magento\Catalog\Api\ProductRepositoryInterfaceFactory $productRepositoryFactory,
        array $data = [],
        ?RtlTextHandler $rtlTextHandler = null
        ) {
            $this->appEmulation = $appEmulation;
            $this->rtlTextHandler = $rtlTextHandler ?: ObjectManager::getInstance()->get(RtlTextHandler::class);
            parent::__construct(
                $paymentData,
                $string,
                $scopeConfig,
                $filesystem,
                $pdfConfig,
                $pdfTotalFactory,
                $pdfItemsFactory,
                $localeDate,
                $inlineTranslation,
                $addressRenderer,
                $storeManager,
                $appEmulation,
                $data
                );
            $this->_productRepositoryFactory = $productRepositoryFactory;
    }
    
    /**
     * Return PDF document
     *
     * @param array|Collection $invoices
     * @return \Zend_Pdf
     */
    public function getPdf($invoices = [])
    {
        $this->_beforeGetPdf();
        $this->_initRenderer('invoice');
        
        $pdf = new \Zend_Pdf();
        $this->_setPdf($pdf);
        $style = new \Zend_Pdf_Style();
        $this->_setFontBold($style, 10);
        
        foreach ($invoices as $invoice) {
            if ($invoice->getStoreId()) {
                $this->appEmulation->startEnvironmentEmulation(
                    $invoice->getStoreId(),
                    \Magento\Framework\App\Area::AREA_FRONTEND,
                    true
                    );
                $this->_storeManager->setCurrentStore($invoice->getStoreId());
            }
            $page = $this->newPage();
            $order = $invoice->getOrder();
            /* Add image */
            $this->insertLogo($page, $invoice->getStore());
            /* Add address */
            $this->insertAddress($page, $invoice->getStore());
            /* Add head */
            $this->insertOrder(
                $page,
                $order,
                $this->_scopeConfig->isSetFlag(
                    self::XML_PATH_SALES_PDF_INVOICE_PUT_ORDER_ID,
                    \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                    $order->getStoreId()
                    )
                );
            /* Add document text and number */
            $this->insertDocumentNumber($page, __('Invoice # ') . $invoice->getIncrementId());
            /* Add table */
            $this->_drawHeader($page);
            /* Add body */
            foreach ($invoice->getAllItems() as $item) {
                if ($item->getOrderItem()->getParentItem()) {
                    continue;
                }
                /* Draw item */
                $this->_drawItem($item, $page, $order);
                /* Insert the product image. You can play with position here */
                $this->insertImage($item, 35, (int)($this->y-35), 95, (int)($this->y+15), $page);
                $page = end($pdf->pages);
            }
            /* Add totals */
            $this->insertTotals($page, $invoice);
            if ($invoice->getStoreId()) {
                $this->appEmulation->stopEnvironmentEmulation();
            }
        }
        $this->_afterGetPdf();
        return $pdf;
    }
    
    /**
     * Insert order to pdf page.
     *
     * @param \Zend_Pdf_Page $page
     * @param \Magento\Sales\Model\Order $obj
     * @param bool $putOrderId
     * @return void
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    protected function insertOrder(&$page, $obj, $putOrderId = true)
    {
        if ($obj instanceof \Magento\Sales\Model\Order) {
            $shipment = null;
            $order = $obj;
        } elseif ($obj instanceof \Magento\Sales\Model\Order\Shipment) {
            $shipment = $obj;
            $order = $shipment->getOrder();
        }
        
        $this->y = $this->y ? $this->y : 815;
        $top = $this->y;
        
        $page->setFillColor(new \Zend_Pdf_Color_GrayScale(1));
        $page->setLineColor(new \Zend_Pdf_Color_GrayScale(0.45));
        $page->drawRectangle(25, $top, 570, $top - 55);
        $page->setFillColor(new \Zend_Pdf_Color_GrayScale(0));
        $this->setDocHeaderCoordinates([25, $top, 570, $top - 55]);
        $this->_setFontRegular($page, 10);
        
        if ($putOrderId) {
            $page->drawText(__('Order # ') . $order->getRealOrderId(), 35, $top -= 30, 'UTF-8');
            $top +=15;
        }
        
        $top -=30;
        $page->drawText(
            __('Order Date: ') .
            $this->_localeDate->formatDate(
                $this->_localeDate->scopeDate(
                    $order->getStore(),
                    $order->getCreatedAt(),
                    true
                    ),
                \IntlDateFormatter::MEDIUM,
                false
                ),
            35,
            $top,
            'UTF-8'
            );
        
        $top -= 10;
        $page->setFillColor(new \Zend_Pdf_Color_Rgb(0.93, 0.92, 0.92));
        $page->setLineColor(new \Zend_Pdf_Color_GrayScale(0.5));
        $page->setLineWidth(0.5);
        $page->drawRectangle(25, $top, 275, $top - 25);
        $page->drawRectangle(275, $top, 570, $top - 25);
        
        /* Calculate blocks info */
        
        /* Billing Address */
        $billingAddress = $this->_formatAddress($this->addressRenderer->format($order->getBillingAddress(), 'pdf'));
        
        /* Payment */
        $paymentInfo = $this->_paymentData->getInfoBlock($order->getPayment())->setIsSecureMode(true)->toPdf();
        $paymentInfo = htmlspecialchars_decode($paymentInfo, ENT_QUOTES);
        $payment = explode('{{pdf_row_separator}}', $paymentInfo);
        foreach ($payment as $key => $value) {
            if (strip_tags(trim($value)) == '') {
                unset($payment[$key]);
            }
        }
        reset($payment);
        
        /* Shipping Address and Method */
        if (!$order->getIsVirtual()) {
            /* Shipping Address */
            $shippingAddress = $this->_formatAddress(
                $this->addressRenderer->format($order->getShippingAddress(), 'pdf')
                );
            $shippingMethod = $order->getShippingDescription();
        }
        
        $page->setFillColor(new \Zend_Pdf_Color_GrayScale(0));
        $this->_setFontBold($page, 12);
        $page->drawText(__('Sold to:'), 35, $top - 15, 'UTF-8');
        
        if (!$order->getIsVirtual()) {
            $page->drawText(__('Ship to:'), 285, $top - 15, 'UTF-8');
        } else {
            $page->drawText(__('Payment Method:'), 285, $top - 15, 'UTF-8');
        }
        
        $addressesHeight = $this->_calcAddressHeight($billingAddress);
        if (isset($shippingAddress)) {
            $addressesHeight = max($addressesHeight, $this->_calcAddressHeight($shippingAddress));
        }
        
        $page->setFillColor(new \Zend_Pdf_Color_GrayScale(1));
        $page->drawRectangle(25, $top - 25, 570, $top - 33 - $addressesHeight);
        $page->setFillColor(new \Zend_Pdf_Color_GrayScale(0));
        $this->_setFontRegular($page, 10);
        $this->y = $top - 40;
        $addressesStartY = $this->y;
        
        foreach ($billingAddress as $value) {
            if ($value !== '') {
                $text = [];
                foreach ($this->string->split($value, 45, true, true) as $_value) {
                    $text[] = $this->rtlTextHandler->reverseRtlText($_value);
                }
                foreach ($text as $part) {
                    $page->drawText(strip_tags(ltrim($part)), 35, $this->y, 'UTF-8');
                    $this->y -= 15;
                }
            }
        }
        
        $addressesEndY = $this->y;
        
        if (!$order->getIsVirtual()) {
            $this->y = $addressesStartY;
            foreach ($shippingAddress as $value) {
                if ($value !== '') {
                    $text = [];
                    foreach ($this->string->split($value, 45, true, true) as $_value) {
                        $text[] = $this->rtlTextHandler->reverseRtlText($_value);
                    }
                    foreach ($text as $part) {
                        $page->drawText(strip_tags(ltrim($part)), 285, $this->y, 'UTF-8');
                        $this->y -= 15;
                    }
                }
            }
            
            $addressesEndY = min($addressesEndY, $this->y);
            $this->y = $addressesEndY;
            
            $page->setFillColor(new \Zend_Pdf_Color_Rgb(0.93, 0.92, 0.92));
            $page->setLineWidth(0.5);
            $page->drawRectangle(25, $this->y, 275, $this->y - 25);
            $page->drawRectangle(275, $this->y, 570, $this->y - 25);
            
            $this->y -= 15;
            $this->_setFontBold($page, 12);
            $page->setFillColor(new \Zend_Pdf_Color_GrayScale(0));
            $page->drawText(__('Payment Method:'), 35, $this->y, 'UTF-8');
            $page->drawText(__('Shipping Method:'), 285, $this->y, 'UTF-8');
            
            $this->y -= 10;
            $page->setFillColor(new \Zend_Pdf_Color_GrayScale(1));
            
            $this->_setFontRegular($page, 10);
            $page->setFillColor(new \Zend_Pdf_Color_GrayScale(0));
            
            $paymentLeft = 35;
            $yPayments = $this->y - 15;
        } else {
            $yPayments = $addressesStartY;
            $paymentLeft = 285;
        }
        
        foreach ($payment as $value) {
            if (trim($value) != '') {
                //Printing "Payment Method" lines
                $value = preg_replace('/<br[^>]*>/i', "\n", $value);
                foreach ($this->string->split($value, 45, true, true) as $_value) {
                    $page->drawText(strip_tags(trim($_value)), $paymentLeft, $yPayments, 'UTF-8');
                    $yPayments -= 15;
                }
            }
        }
        
        if ($order->getIsVirtual()) {
            // replacement of Shipments-Payments rectangle block
            $yPayments = min($addressesEndY, $yPayments);
            $page->drawLine(25, $top - 25, 25, $yPayments);
            $page->drawLine(570, $top - 25, 570, $yPayments);
            $page->drawLine(25, $yPayments, 570, $yPayments);
            
            $this->y = $yPayments - 15;
        } else {
            $topMargin = 15;
            $methodStartY = $this->y;
            $this->y -= 15;
            
            foreach ($this->string->split($shippingMethod, 45, true, true) as $_value) {
                $page->drawText(strip_tags(trim($_value)), 285, $this->y, 'UTF-8');
                $this->y -= 15;
            }
            
            $yShipments = $this->y;
            $totalShippingChargesText = "("
                . __('Total Shipping Charges')
                . " "
                    . $order->formatPriceTxt($order->getShippingAmount())
                    . ")";
                    
                    $page->drawText($totalShippingChargesText, 285, $yShipments - $topMargin, 'UTF-8');
                    $yShipments -= $topMargin + 10;
                    
                    $tracks = [];
                    if ($shipment) {
                        $tracks = $shipment->getAllTracks();
                    }
                    if (count($tracks)) {
                        $page->setFillColor(new \Zend_Pdf_Color_Rgb(0.93, 0.92, 0.92));
                        $page->setLineWidth(0.5);
                        $page->drawRectangle(285, $yShipments, 510, $yShipments - 10);
                        $page->drawLine(400, $yShipments, 400, $yShipments - 10);
                        //$page->drawLine(510, $yShipments, 510, $yShipments - 10);
                        
                        $this->_setFontRegular($page, 9);
                        $page->setFillColor(new \Zend_Pdf_Color_GrayScale(0));
                        //$page->drawText(__('Carrier'), 290, $yShipments - 7 , 'UTF-8');
                        $page->drawText(__('Title'), 290, $yShipments - 7, 'UTF-8');
                        $page->drawText(__('Number'), 410, $yShipments - 7, 'UTF-8');
                        
                        $yShipments -= 20;
                        $this->_setFontRegular($page, 8);
                        foreach ($tracks as $track) {
                            $maxTitleLen = 45;
                            $endOfTitle = strlen($track->getTitle()) > $maxTitleLen ? '...' : '';
                            $truncatedTitle = substr($track->getTitle(), 0, $maxTitleLen) . $endOfTitle;
                            $page->drawText($truncatedTitle, 292, $yShipments, 'UTF-8');
                            $page->drawText($track->getNumber(), 410, $yShipments, 'UTF-8');
                            $yShipments -= $topMargin - 5;
                        }
                    } else {
                        $yShipments -= $topMargin - 5;
                    }
                    
                    $currentY = min($yPayments, $yShipments);
                    
                    // replacement of Shipments-Payments rectangle block
                    $page->drawLine(25, $methodStartY, 25, $currentY);
                    //left
                    $page->drawLine(25, $currentY, 570, $currentY);
                    //bottom
                    $page->drawLine(570, $currentY, 570, $methodStartY);
                    //right
                    
                    $this->y = $currentY;
                    $this->y -= 15;
        }
    }
    
    /**
     * Insert title and number for concrete document type
     *
     * @param  \Zend_Pdf_Page $page
     * @param  string $text
     * @return void
     */
    public function insertDocumentNumber(\Zend_Pdf_Page $page, $text)
    {
        $page->setFillColor(new \Zend_Pdf_Color_GrayScale(0));
        $this->_setFontRegular($page, 10);
        $docHeader = $this->getDocHeaderCoordinates();
        $page->drawText($text, 35, $docHeader[1] - 15, 'UTF-8');
    }
    
    /**
     * Draws the product image using the specified coordinates
     *
     * @param DataObject $item
     * @param int $x1
     * @param int $y1
     * @param int $x2
     * @param int $y2
     * @param \Zend_Pdf_Page &$page
     * @return bool
     */
    protected function insertImage($item, $x1, $y1, $x2, $y2, \Zend_Pdf_Page &$page)
    {
        try{
            $product = $this->_productRepositoryFactory->create()->getById($item->getProductId());
            $productImage = '/catalog/product' . $product->getData('thumbnail');
            $pdfImage = \Zend_Pdf_Image::imageWithPath($this->_mediaDirectory->getAbsolutePath($productImage));
            
            //Draw image to PDF
            $page->drawImage($pdfImage, $x1, $y1, $x2, $y2);
        }
        catch (\Exception $e) {
            return false;
        }
    }
}
