<?php

/*
 * This file is part of the Chameleon System (https://www.chameleonsystem.com).
 *
 * (c) ESONO AG (https://www.esono.de)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

class TShopOrderItem extends TAdbShopOrderItem
{
    const VIEW_PATH = 'pkgShop/views/db/TShopOrderItem';

    /**
     * loads the owning bundle order item IF this item belongs to a bundle. returns false if it is not.
     *
     * @return TdbShopOrderItem
     */
    public function &GetOwningBundleOrderItem()
    {
        $oOwningOrderItem = $this->GetFromInternalCache('oOwningOrderItem');
        if (is_null($oOwningOrderItem)) {
            $oOwningOrderItem = false;
            if (!is_null($this->id)) {
                $query = "SELECT `shop_order_item`.*
                      FROM `shop_order_item`
                INNER JOIN `shop_order_bundle_article` ON `shop_order_item`.`id` = `shop_order_bundle_article`.`shop_order_item_id`
                     WHERE `shop_order_bundle_article`.`bundle_article_id` = '".MySqlLegacySupport::getInstance()->real_escape_string($this->id)."'
                   ";
                if ($aOwner = MySqlLegacySupport::getInstance()->fetch_assoc(MySqlLegacySupport::getInstance()->query($query))) {
                    $oOwningOrderItem = TdbShopOrderItem::GetNewInstance();
                    /** @var $oOwningOrderItem TdbShopOrderItem */
                    $oOwningOrderItem->LoadFromRow($aOwner);
                }
            }
            $this->SetInternalCache('oOwningOrderItem', $oOwningOrderItem);
        }

        return $oOwningOrderItem;
    }

    /**
     * if this order item belongs to a bundle, then this method will return the connecting table.
     *
     * @return TdbShopOrderBundleArticle
     */
    public function GetOwningBundleConnection()
    {
        $oOwningBundleConnection = $this->GetFromInternalCache('oOwningBundleConnection');
        if (is_null($oOwningBundleConnection)) {
            $oOwningBundleConnection = false;
            if (!is_null($this->id)) {
                $oOwningBundleConnection = TdbShopOrderBundleArticle::GetNewInstance();
                /** @var $oOwningOrderItem TdbShopOrderBundleArticle */
                if (!$oOwningBundleConnection->LoadFromField('bundle_article_id', $this->id)) {
                    $oOwningBundleConnection = false;
                }
            }
            $this->SetInternalCache('oOwningBundleConnection', $oOwningBundleConnection);
        }

        return $oOwningBundleConnection;
    }

    /**
     * returns true if the item belongs to a bundle.
     *
     * @return bool
     */
    public function BelongsToABundle()
    {
        return false !== $this->GetOwningBundleConnection();
    }

    /**
     * is called before the item is saved. $this->sqlData will hold the new data
     * while the original is still in the database.
     *
     * @param bool $bIsInsert - set to true if this is an insert
     */
    protected function PreSaveHook($bIsInsert)
    {
        parent::PreSaveHook($bIsInsert);
        if (!$bIsInsert) {
            $oOldData = TdbShopOrderItem::GetNewInstance();
            if ($oOldData->Load($this->id)) {
                $bNewAmountIsDelta = true;
                $bUpdateSaleCounter = true;
                $bSameArticle = ($oOldData->fieldShopArticleId == $this->sqlData['shop_article_id']);
                if ($bSameArticle) {
                    $dAmountDelta = $oOldData->fieldOrderAmount - $this->sqlData['order_amount'];
                    $oArticle = $this->GetFieldShopArticle();
                    $oArticle->UpdateStock($dAmountDelta, $bNewAmountIsDelta, $bUpdateSaleCounter);
                } else {
                    $oOldArticle = $oOldData->GetFieldShopArticle();
                    $oOldArticle->UpdateStock($oOldData->fieldOrderAmount, $bNewAmountIsDelta, $bUpdateSaleCounter);

                    $oNewArticle = TdbShopArticle::GetNewInstance();
                    if ($oNewArticle->Load($this->sqlData['shop_article_id'])) {
                        $oNewArticle->UpdateStock(-1 * $this->sqlData['order_amount'], $bNewAmountIsDelta, $bUpdateSaleCounter);
                    }
                }
            }
        }
    }

    /**
     * use the method to up the sales count for the article.
     */
    protected function PostInsertHook()
    {
        parent::PostInsertHook();
        $oArticle = &$this->GetFieldShopArticle();
        if (!is_null($oArticle)) {
            $bNewAmountIsDelta = true;
            $bUpdateSaleCounter = true;
            $oArticle->UpdateStock(-1 * $this->fieldOrderAmount, $bNewAmountIsDelta, $bUpdateSaleCounter);
        }
    }

    /**
     * update the article counter.
     */
    protected function PreDeleteHook()
    {
        parent::PreDeleteHook();
        $oArticle = &$this->GetFieldShopArticle();
        if (!is_null($oArticle)) {
            $bNewAmountIsDelta = true;
            $bUpdateSaleCounter = true;
            $oArticle->UpdateStock($this->fieldOrderAmount, $bNewAmountIsDelta, $bUpdateSaleCounter);
        }
    }

    /**
     * used to display an order item.
     *
     * @param string $sViewName     - the view to use
     * @param string $sViewType     - where the view is located (Core, Custom-Core, Customer)
     * @param array  $aCallTimeVars - place any custom vars that you want to pass through the call here
     *
     * @return string
     */
    public function Render($sViewName = 'standard', $sViewType = 'Core', $aCallTimeVars = array())
    {
        $oView = new TViewParser();
        $oView->AddVar('oOrderItem', $this);
        $oView->AddVar('aCallTimeVars', $aCallTimeVars);
        $aOtherParameters = $this->GetAdditionalViewVariables($sViewName, $sViewType);
        $oView->AddVarArray($aOtherParameters);

        return $oView->RenderObjectPackageView($sViewName, self::VIEW_PATH, $sViewType);
    }

    /**
     * return true if this is a download product.
     *
     * @return bool
     */
    public function isDownload()
    {
        $db = \ChameleonSystem\CoreBundle\ServiceLocator::get('database_connection');
        $query = 'select COUNT(*) AS matches FROM `shop_order_item_download_cms_document_mlt`
                   WHERE `source_id` = :shopOrderItemId';
        $result = $db->fetchAssoc($query, array('shopOrderItemId' => $this->id));

        return intval($result['matches']) > 0;
    }

    /**
     * use this method to add any variables to the render method that you may
     * require for some view.
     *
     * @param string $sViewName - the view being requested
     * @param string $sViewType - the location of the view (Core, Custom-Core, Customer)
     *
     * @return array
     */
    protected function GetAdditionalViewVariables($sViewName, $sViewType)
    {
        return array();
    }

    /* SECTION: CACHE RELEVANT METHODS FOR THE RENDER METHOD

    /**
     * Add view based clear cache triggers for the Render method here
     *
     * @param array $aClearTriggers - clear trigger array (with current contents)
     * @param string $sViewName - view being requested
     * @param string $sViewType - location of the view (Core, Custom-Core, Customer)
     *
     * @deprecated since 6.2.0 - no longer used.
     */
    protected function AddClearCacheTriggers(&$aClearTriggers, $sViewName, $sViewType)
    {
    }

    /**
     * used to set the id of a clear cache (ie. related table).
     *
     * @param string $sTableName - the table name
     *
     * @return int|null|string
     *
     * @deprecated since 6.2.0 - no longer used.
     */
    protected function GetClearCacheTriggerTableValue($sTableName)
    {
        $sValue = '';
        switch ($sTableName) {
            case $this->table:
                $sValue = $this->id;
                break;

            default:
                break;
        }

        return $sValue;
    }

    /**
     * returns an array with all table names that are relevant for the render function.
     *
     * @param string $sViewName - the view name being requested (if know by the caller)
     * @param string $sViewType - the view type (core, custom-core, customer) being requested (if know by the caller)
     *
     * @return array
     */
    public static function GetCacheRelevantTables($sViewName = null, $sViewType = null)
    {
        $aTables = array();
        $aTables[] = 'shop_order_item';

        return $aTables;
    }
}