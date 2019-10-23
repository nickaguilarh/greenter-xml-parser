<?php
/**
 * Created by PhpStorm.
 * User: Giansalex
 * Date: 05/10/2017
 * Time: 08:14
 */

namespace Greenter\Xml\Parser;

use Greenter\Model\Client\Client;
use Greenter\Model\Company\Address;
use Greenter\Model\Company\Company;
use Greenter\Model\DocumentInterface;
use Greenter\Model\Sale\Detraction;
use Greenter\Model\Sale\Document;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Sale\Legend;
use Greenter\Model\Sale\Prepayment;
use Greenter\Model\Sale\SaleDetail;
use Greenter\Model\Sale\SalePerception;
use Greenter\Parser\DocumentParserInterface;

/**
 * Class InvoiceParser
 * @package Greenter\Xml\Parser
 */
class InvoiceParser implements DocumentParserInterface
{
    /**
     * @param $value
     * @return DocumentInterface
     */
    public function parse($value)
    {
        $xpt = $this->getXpath($value);
        $inv = new Invoice();
        $version = $this->defValue($xpt->query('/xt:Invoice/cbc:UBLVersionID'));
        $docFac = explode('-', $this->defValue($xpt->query('/xt:Invoice/cbc:ID')));
        $issueDate = $this->defValue($xpt->query('/xt:Invoice/cbc:IssueDate'));
        $issueTime = $this->defValue($xpt->query('/xt:Invoice/cbc:IssueTime'));
        $dateTime = new \DateTime($issueDate . ' ' . $issueTime);
        $inv->setSerie($docFac[0])
            ->setCorrelativo($docFac[1])
            ->setTipoDoc((string)$this->defValue($xpt->query('/xt:Invoice/cbc:InvoiceTypeCode')))
            ->setTipoMoneda($this->defValue($xpt->query('/xt:Invoice/cbc:DocumentCurrencyCode')))
            ->setFechaEmision($dateTime)
            ->setCompany($this->getCompany($xpt))
            ->setClient($this->getClient($xpt));

        $extensions = $xpt->query('/xt:Invoice/ext:UBLExtensions')->item(0);
        $this->loadTotals($inv, $xpt);
        $this->loadTributos($inv, $xpt);
        $monetaryTotal = $xpt->query('/xt:Invoice/cac:LegalMonetaryTotal')->item(0);
        $inv->setTipoOperacion($xpt->query('/xt:Invoice/cbc:InvoiceTypeCode')->item(0)->getAttribute('listID'))
            ->setSumDsctoGlobal(floatval($this->defValue($xpt->query('cbc:AllowanceTotalAmount', $monetaryTotal),0)))
            ->setTotalAnticipos(floatval($this->defValue($xpt->query('cbc:PrepaidAmount', $monetaryTotal),0)))
            ->setAnticipos(iterator_to_array($this->getPrepayments($xpt)))
            ->setMtoOtrosTributos(floatval($this->defValue($xpt->query('cbc:ChargeTotalAmount', $monetaryTotal), 0)))
            ->setMtoImpVenta(floatval($this->defValue($xpt->query('cbc:PayableAmount', $monetaryTotal),0)))
            ->setDetails(iterator_to_array($this->getDetails($xpt)))
            ->setLegends(iterator_to_array($this->getLegends($xpt)));
        $this->loadExtras($xpt, $inv);

        return $inv;
    }

    private function getXpath($value)
    {
        if ($value instanceof \DOMDocument) {
            $doc = $value;
        } else {
            $doc = new \DOMDocument();
            @$doc->loadXML($value);
        }
        $rootNamespace = $doc->documentElement->namespaceURI;
        $xpt = new \DOMXPath($doc);
        $xpt->registerNamespace('xt', $rootNamespace);

        return $xpt;
    }

    private function defValue(\DOMNodeList $nodeList, $default = '')
    {
        if ($nodeList->length == 0) {
            return $default;
        }

        return $nodeList->item(0)->nodeValue;
    }

    private function loadTotals(Invoice $inv, \DOMXPath $xpt)
    {
        $totals = $xpt->query('/xt:Invoice/cac:LegalMonetaryTotal')->item(0);
//        $lineExtension = $this->defValue($xpt->query('cbc:LineExtensionAmount', $totals),0);
//        $taxInclusiveAmount = $this->defValue($xpt->query('cbc:TaxInclusiveAmount', $totals),0);
//        $allowanceTotalAmount = $this->defValue($xpt->query('cbc:AllowanceTotalAmount', $totals),0);
//        $chargeTotalAmount = $this->defValue($xpt->query('cbc:ChargeTotalAmount', $totals),0);
//        $prepaidAmount = $this->defValue($xpt->query('cbc:PrepaidAmount', $totals),0);
//        $payableAmount = $this->defValue($xpt->query('cbc:PayableAmount', $totals),0);
//        dd($lineExtension, $taxInclusiveAmount, $allowanceTotalAmount, $chargeTotalAmount, $prepaidAmount, $payableAmount);

        foreach ($totals->childNodes as $node) {
            /**@var $node \DOMElement*/
            if ($node->nodeType == XML_ELEMENT_NODE) {
                $val = $node->nodeValue;
                $name = $node->nodeName;
                switch ($node->nodeName) {
                    case 'cbc:LineExtensionAmount':
                        $inv->setMtoOperGravadas(floatval($val));
                        break;
                    case '1002':
                        $inv->setMtoOperInafectas($val);
                        break;
                    case '1003':
                        $inv->setMtoOperExoneradas($val);
                        break;
                    case '1004':
                        $inv->setMtoOperGratuitas($val);
                        break;
                    case '2001':
                        $inv->setPerception((new SalePerception())
                            ->setCodReg($xpt->query('cbc:ID', $total)->item(0)->getAttribute('schemeID'))
                            ->setMto($val)
                            ->setMtoBase(floatval($this->defValue($xpt->query('sac:ReferenceAmount', $total), 0)))
                            ->setMtoTotal(floatval($this->defValue($xpt->query('sac:TotalAmount', $total),0))));
                        break;
                    case '2003':
                        $inv->setDetraccion((new Detraction())
                            ->setMount($val)
                            ->setPercent(floatval($this->defValue($xpt->query('cbc:Percent', $total),0)))
                            ->setValueRef(floatval($this->defValue($xpt->query('sac:ReferenceAmount', $total),0))));
                        break;
                    case 'cbc:AllowanceTotalAmount':
                        $inv->setMtoDescuentos(floatval($val));
                        break;
                }
            }
        }
    }

    private function loadTributos(Invoice $inv, \DOMXPath $xpt)
    {
        $taxs = $xpt->query('/xt:Invoice/cac:TaxTotal/cac:TaxSubtotal');
        foreach ($taxs as $tax) {
            $name = $this->defValue($xpt->query('cac:TaxCategory/cac:TaxScheme/cbc:Name', $tax));
            $val = floatval($this->defValue($xpt->query('cbc:TaxAmount', $tax),0));
            switch ($name) {
                case 'IGV':
                    $inv->setMtoIGV($val);
                    break;
                case 'ISC':
                    $inv->setMtoISC($val);
                    break;
                case 'OTROS':
                    $inv->setSumOtrosCargos($val);
                    break;
            }
        }
    }

    private function getPrepayments(\DOMXPath $xpt)
    {
        $nodes = $xpt->query('/xt:Invoice/cac:PrepaidPayment');
        if ($nodes->length == 0) {
            return;
        }
        foreach ($nodes as $node) {
            $docRel = $xpt->query('cbc:ID', $node)->item(0);
            $item = (new Prepayment())
                ->setTotal(floatval($this->defValue($xpt->query('cbc:PaidAmount', $node),0)))
                ->setTipoDocRel($docRel->getAttribute('schemeID'))
                ->setNroDocRel($docRel->nodeValue);

            yield $item;
        }
    }

    private function getLegends(\DOMXPath $xpt, \DOMNode $node = null)
    {
        if (empty($node)) {
            return;
        }

        $legends = $xpt->query('sac:AdditionalProperty', $node);
        foreach ($legends as $legend) {
            /**@var $legend \DOMElement*/
            $leg = (new Legend())
                ->setCode($this->defValue($xpt->query('cbc:ID', $legend)))
                ->setValue($this->defValue($xpt->query('cbc:Value', $legend)));

            yield $leg;
        }
    }

    private function getClient(\DOMXPath $xp)
    {
        $node = $xp->query('/xt:Invoice/cac:AccountingCustomerParty')->item(0);
        $document = $xp->query('cac:Party/cac:PartyIdentification/cbc:ID', $node)->item(0);
        $cl = new Client();
        $cl->setNumDoc($document->nodeValue)
            ->setTipoDoc($document->getAttribute('schemeID'))
            ->setRznSocial($this->defValue($xp->query('cac:Party/cac:PartyLegalEntity/cbc:RegistrationName', $node)))
            ->setAddress($this->getAddress($xp, $node));

        return $cl;
    }

    private function getCompany(\DOMXPath $xp)
    {
        $node = $xp->query('/xt:Invoice/cac:AccountingSupplierParty')->item(0);

        $cl = new Company();
        $cl->setRuc($this->defValue($xp->query('cac:Party/cac:PartyIdentification/cbc:ID', $node)))
            ->setNombreComercial($this->defValue($xp->query('cac:Party/cac:PartyName/cbc:Name', $node)))
            ->setRazonSocial($this->defValue($xp->query('cac:Party/cac:PartyLegalEntity/cbc:RegistrationName', $node)))
            ->setAddress($this->getAddress($xp, $node));

        return $cl;
    }

    private function loadExtras(\DOMXPath $xpt, Invoice $inv)
    {
        $inv->setCompra($this->defValue($xpt->query('/xt:Invoice/cac:OrderReference/cbc:ID')));
        $fecVen = $this->defValue($xpt->query('/xt:Invoice/cac:PaymentMeans/cbc:PaymentDueDate'));
        if (!empty($fecVen)) {
            $inv->setFecVencimiento(new \DateTime($fecVen));
        }

        $inv->setGuias(iterator_to_array($this->getGuias($xpt)));
    }

    private function getGuias(\DOMXPath $xpt)
    {
        $guias = $xpt->query('/xt:Invoice/cac:DespatchDocumentReference');
        if ($guias->length == 0) {
            return;
        }

        foreach ($guias as $guia) {
            $item = new Document();
            $item->setTipoDoc($this->defValue($xpt->query('cbc:DocumentTypeCode', $guia)));
            $item->setNroDoc($this->defValue($xpt->query('cbc:ID', $guia)));

            yield $item;
        }
    }

    /**
     * @param \DOMXPath $xp
     * @param $node
     * @return Address|null
     */
    private function getAddress(\DOMXPath $xp, $node)
    {
        $nAd = $xp->query('cac:Party/cac:PartyLegalEntity/cac:RegistrationAddress', $node);
        if ($nAd->length > 0) {
            $address = $nAd->item(0);

            return (new Address())
                ->setDireccion($this->defValue($xp->query('cac:AddressLine/cbc:Line', $address)))
                ->setDepartamento($this->defValue($xp->query('cbc:CityName', $address)))
                ->setProvincia($this->defValue($xp->query('cbc:CountrySubentity', $address)))
                ->setDistrito($this->defValue($xp->query('cbc:District', $address)))
                ->setUbigueo($this->defValue($xp->query('cbc:CountrySubentityCode', $address)));
        }

        return null;
    }

    private function getDetails(\DOMXPath $xpt)
    {
        $nodes = $xpt->query('/xt:Invoice/cac:InvoiceLine');

        foreach ($nodes as $node) {
            $quant = $xpt->query('cbc:InvoicedQuantity', $node)->item(0);
            $det = new SaleDetail();
            $description = explode(' ',$this->defValue($xpt->query('cac:Item/cbc:Description', $node)));
            $productCode = $description[0];
            unset($description[0]);
            $description = implode(' ', $description);
            $det->setCantidad(floatval($quant->nodeValue))
                ->setUnidad($quant->getAttribute('unitCode'))
                ->setMtoValorVenta(floatval($this->defValue($xpt->query('cbc:LineExtensionAmount', $node))))
                ->setMtoValorUnitario(floatval($this->defValue($xpt->query('cac:Price/cbc:PriceAmount', $node))))
                ->setDescripcion($description)
                ->setCodProducto($productCode)
                ->setCodProdSunat($this->defValue($xpt->query('cac:Item/cac:CommodityClassification/cbc:ItemClassificationCode', $node)));

            $taxs = $xpt->query('cac:TaxTotal/cac:TaxSubtotal', $node);
            foreach ($taxs as $tax) {
                $name = $this->defValue($xpt->query('cac:TaxCategory/cac:TaxScheme/cbc:Name', $tax));
                $val = floatval($this->defValue($xpt->query('cbc:TaxAmount', $tax),0));
                $percentage = floatval($this->defValue($xpt->query('cac:TaxCategory/cbc:Percent', $tax)));
                switch ($name) {
                    case 'IGV':
                        $det->setIgv($val);
                        $det->setIgvPorc($percentage);
                        $det->setTipAfeIgv($this->defValue($xpt->query('cac:TaxCategory/cbc:TaxExemptionReasonCode', $tax)));
                        break;
                    case 'ISC':
                        $det->setIsc($val);
                        $det->setIscPorc($percentage);
                        $det->setTipSisIsc($this->defValue($xpt->query('cac:TaxCategory/cbc:TierRange', $tax)));
                        break;
                }
            }

            // Descuento
            $descs = $xpt->query('cac:AllowanceCharge', $node);
            foreach ($descs as $desc) {
                $charge = $this->defValue($xpt->query('cbc:ChargeIndicator', $desc));
                $charge = trim($charge);
                if ($charge == 'false') {
                    $val = floatval($this->defValue($xpt->query('cbc:Amount', $desc),0));
                    $det->setDescuento($val);
                }
            }

            $prices = $xpt->query('cac:PricingReference', $node);
            foreach ($prices as $price) {
                $code = $this->defValue($xpt->query('cac:AlternativeConditionPrice/cbc:PriceTypeCode', $price));
                $value = floatval($this->defValue($xpt->query('cac:AlternativeConditionPrice/cbc:PriceAmount', $price),0));
                $code = trim($code);

                switch ($code) {
                    case '01':
                        $det->setMtoPrecioUnitario($value);
                        break;
                    case '02':
                        $det->setMtoValorGratuito($value);
                        break;
                }
            }

            yield $det;
        }
    }
}
