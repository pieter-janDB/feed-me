<?php
namespace verbb\feedme\fields;

use verbb\feedme\FeedMe;
use verbb\feedme\base\Field;
use verbb\feedme\base\FieldInterface;
use verbb\feedme\helpers\AssetHelper;
use verbb\feedme\helpers\DataHelper;

use Craft;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset as AssetElement;
use craft\helpers\Assets as AssetsHelper;
use craft\helpers\Db;
use craft\helpers\UrlHelper;

use Cake\Utility\Hash;

class Assets extends Field implements FieldInterface
{
    // Properties
    // =========================================================================

    public static $name = 'Assets';
    public static $class = 'craft\fields\Assets';
    public static $elementType = 'craft\elements\Asset';
    private $_uploadData = [];


    // Templates
    // =========================================================================

    public function getMappingTemplate()
    {
        return 'feed-me/_includes/fields/assets';
    }


    // Public Methods
    // =========================================================================

    public function parseField()
    {
        $value = $this->fetchArrayValue();

        $settings = Hash::get($this->field, 'settings');
        $folders = Hash::get($this->field, 'settings.sources');
        $limit = Hash::get($this->field, 'settings.limit');
        $targetSiteId = Hash::get($this->field, 'settings.targetSiteId');
        $feedSiteId = Hash::get($this->feed, 'siteId');
        $upload = Hash::get($this->fieldInfo, 'options.upload');
        $conflict = Hash::get($this->fieldInfo, 'options.conflict');
        $fields = Hash::get($this->fieldInfo, 'fields');
        $node = Hash::get($this->fieldInfo, 'node');

        // Get folder id's for connecting
        $folderIds = $this->field->resolveDynamicPathToFolderId($this->element);

        if (!$folderIds) {
            if (is_array($folders)) {
                foreach ($folders as $folder) {
                    list($volume, $uid) = explode(':', $folder);
                    $volumeId = Db::idByUid(Table::VOLUMES, $uid);

                    // Get all folders for this volume
                    $ids = (new Query())
                        ->select(['id'])
                        ->from([Table::VOLUMEFOLDERS])
                        ->where(['volumeId' => $volumeId])
                        ->column();

                    $folderIds = array_merge($folderIds, $ids);
                }
            } else if ($folders === '*') {
                $folderIds = null;
            }
        }

        $foundElements = [];
        $urlsToUpload = [];
        $base64ToUpload = [];

        if (!$value) {
            return $foundElements;
        }

        foreach ($value as $key => $dataValue) {
            // Prevent empty or blank values (string or array), which match all elements
            if (empty($dataValue)) {
                continue;
            }

            // If we're using the default value - skip, we've already got an id array
            if ($node === 'usedefault') {
                $foundElements = $value;
                break;
            }

            $query = AssetElement::find();

            // In multi-site, there's currently no way to query across all sites - we use the current site
            // See https://github.com/craftcms/cms/issues/2854
            if (Craft::$app->getIsMultiSite()) {
                if ($targetSiteId) {
                    $criteria['siteId'] = Craft::$app->getSites()->getSiteByUid($targetSiteId)->id;
                } else if ($feedSiteId) {
                    $criteria['siteId'] = $feedSiteId;
                } else {
                    $criteria['siteId'] = Craft::$app->getSites()->getCurrentSite()->id;
                }
            }

            // If we're uploading files, this will need to be an absolute URL. If it is, save until later.
            // We also don't check for existing assets here, so break out instantly.
            if ($upload && UrlHelper::isAbsoluteUrl($dataValue)) {
                $urlsToUpload[$key] = $dataValue;

                // If we're opting to use the already uploaded asset, we can check here
                if ($conflict === AssetElement::SCENARIO_INDEX) {
                    $dataValue = AssetHelper::getRemoteUrlFilename($dataValue);
                }
            }

            // Check if the URL is actually an base64 encoded file.
            $matches = [];
            preg_match('/^data:\w+\/\w+;base64,/i', $dataValue, $matches);

            if ($upload && count($matches) > 0) {
                $base64ToUpload[$key] = $dataValue;
            }

            $filename = AssetsHelper::prepareAssetName($dataValue);

            $criteria['status'] = null;
            $criteria['folderId'] = $folderIds;
            $criteria['kind'] = $settings['allowedKinds'];
            $criteria['limit'] = $limit;
            $criteria['filename'] = $filename;
            $criteria['includeSubfolders'] = true;

            Craft::configure($query, $criteria);

            $ids = $query->ids();
            $foundElements = array_merge($foundElements, $ids);

            // Are we uploading, and did we find existing assets? No need to process
            if ($upload && $ids && $conflict === AssetElement::SCENARIO_INDEX) {
                unset($urlsToUpload[$key]);
            }
        }

        if ($upload) {
            if ($urlsToUpload) {
                $uploadedElements = AssetHelper::fetchRemoteImage($urlsToUpload, $this->fieldInfo, $this->feed, $this->field, $this->element);
                $foundElements = array_merge($foundElements, $uploadedElements);
            }

            if ($base64ToUpload) {
                $uploadedElements = AssetHelper::createBase64Image($base64ToUpload, $this->fieldInfo, $this->feed, $this->field, $this->element);
                $foundElements = array_merge($foundElements, $uploadedElements);
            }
        }

        // Check for field limit - only return the specified amount
        if ($foundElements && $limit) {
            $foundElements = array_chunk($foundElements, $limit)[0];
        }

        // Check for any sub-fields for the lement
        if ($fields) {
            $this->populateElementFields($foundElements);
        }

        $foundElements = array_unique($foundElements);

        // Protect against sending an empty array - removing any existing elements
        if (!$foundElements) {
            return null;
        }

        return $foundElements;
    }

}
