<?php
namespace StefanFroemken\RepairTranslation\SignalSlot;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2016 Stefan Froemken <froemken@gmail.com>
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Extbase\DomainObject\DomainObjectInterface;
use TYPO3\CMS\Extbase\Persistence\Generic\Qom\Comparison;
use TYPO3\CMS\Extbase\Persistence\Generic\Qom\ConstraintInterface;
use TYPO3\CMS\Extbase\Persistence\Generic\Qom\JoinInterface;
use TYPO3\CMS\Extbase\Persistence\Generic\Qom\LogicalAnd;
use TYPO3\CMS\Extbase\Persistence\Generic\Qom\LogicalOr;
use TYPO3\CMS\Extbase\Persistence\Generic\Qom\SelectorInterface;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;

/**
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3 or later
 */
class Repair
{
    /**
     * @var \TYPO3\CMS\Frontend\Page\PageRepository
     */
    protected $pageRepository;

    /**
     * @var \TYPO3\CMS\Extbase\Service\EnvironmentService
     * @inject
     */
    protected $environmentService;

    /**
     * @var \TYPO3\CMS\Extbase\Persistence\Generic\Mapper\DataMapper
     * @inject
     */
    protected $dataMapper;

    /**
     * @var \StefanFroemken\RepairTranslation\Parser\QueryParser
     * @inject
     */
    protected $queryParser;

    /**
     * Modify sys_file_reference language
     *
     * @param \TYPO3\CMS\Extbase\Persistence\QueryInterface $query
     * @param array $result
     *
     * @return array
     */
    public function modifySysFileReferenceLanguage(QueryInterface $query, array $result)
    {
        if ($this->isSysFileReferenceTable($query)) {
            $newTranslatedReferences = $this->getNewlyCreatedTranslatedSysFileReferences($query);
            $origTranslatedReferences = $this->getFileReferences($result, !empty($newTranslatedReferences));
            $result = array_merge($origTranslatedReferences, $newTranslatedReferences);
        }

        return array(
            0 => $query,
            1 => $result
        );
    }

    /**
     * Gets the records for the object
     *
     * @param array $sysFileReferenceRecords
     * @param bool $languageReferencesExist true if there are standalone file references for the current language
     * @return array
     */
    protected function getFileReferences($sysFileReferenceRecords, $languageReferencesExist = false)
    {
        $records = [];

        if (!empty($sysFileReferenceRecords)) {
            $translationExists = false;
            $l10nMode = $this->getL10nMode($sysFileReferenceRecords[0]);

            foreach ($sysFileReferenceRecords as $key => $record) {
                if ($l10nMode === 'mergeIfNotBlank') {
                    // bypass the record and filter later depending on the translation status
                    $records[] = $record;
                    if (isset($record['_LOCALIZED_UID'])) {
                        $translationExists = true;
                    }
                    continue;
                }

                if ($l10nMode === 'exclude') {
                    // get the original records only
                    if (!isset($record['_LOCALIZED_UID'])) {
                        $records[] = $record;
                    }
                    continue;
                }

                if (isset($record['_LOCALIZED_UID'])) {
                    // The image reference in translated parent record was not manually deleted.
                    // So, l10n_parent is filled and we have a valid translated sys_file_reference record here
                    $records[] = $record;
                    $translationExists = true;
                }
            }

            // filter records for mergedIfNotBlank
            if ($l10nMode === 'mergeIfNotBlank') {
                if ($languageReferencesExist || $translationExists) {
                    $records = array_filter($records, function ($record) {
                        return isset($record['_LOCALIZED_UID']);
                    });
                }
            }
        }

        return $records;
    }

    /**
     * Check for sys_file_reference table
     *
     * @param QueryInterface $query
     *
     * @return bool
     */
    protected function isSysFileReferenceTable(QueryInterface $query)
    {
        $source = $query->getSource();
        if ($source instanceof SelectorInterface) {
            $tableName = $source->getSelectorName();
        } elseif ($source instanceof JoinInterface) {
            $tableName = $source->getRight()->getSelectorName();
        } else {
            $tableName = '';
        }

        return $tableName === 'sys_file_reference';
    }

    /**
     * Get newly created translated sys_file_references,
     * which do not have a relation to the default language
     * This will happen, if you translate a record, delete the sys_file_record and create a new one
     *
     * @param QueryInterface $query
     *
     * @return array
     */
    protected function getNewlyCreatedTranslatedSysFileReferences(QueryInterface $query)
    {
        // Find references which do not have a relation to default language
        $where = array(
            0 => 'sys_file_reference.l10n_parent = 0'
        );
        // add where statements. uid_foreign=UID of translated parent record
        $this->queryParser->parseConstraint($query->getConstraint(), $where);

        if ($this->environmentService->isEnvironmentInFrontendMode()) {
            $where[] = ' 1=1 ' . $this->getPageRepository()->enableFields('sys_file_reference');
        } else {
            $where[] = sprintf(
                ' 1=1 %s %s',
                BackendUtility::BEenableFields('sys_file_reference'),
                BackendUtility::deleteClause('sys_file_reference')
            );
        }
        $rows = $this->getDatabaseConnection()->exec_SELECTgetRows(
            '*',
            'sys_file_reference',
            implode(' AND ', $where),
            '',
            'sorting_foreign ASC'
        );
        if (empty($rows)) {
            $rows = array();
        }

        foreach ($rows as $key => &$row) {
            BackendUtility::workspaceOL('sys_file_reference', $row);
            // t3ver_state=2 indicates that the live element must be deleted upon swapping the versions.
            if ((int)$row['t3ver_state'] === 2) {
                unset($rows[$key]);
            }
        }

        return $rows;
    }

    /**
     * Get page repository
     *
     * @return \TYPO3\CMS\Frontend\Page\PageRepository
     */
    protected function getPageRepository() {
        if (!$this->pageRepository instanceof \TYPO3\CMS\Frontend\Page\PageRepository) {
            if ($this->environmentService->isEnvironmentInFrontendMode() && is_object($GLOBALS['TSFE'])) {
                $this->pageRepository = $GLOBALS['TSFE']->sys_page;
            } else {
                $this->pageRepository = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance('TYPO3\\CMS\\Frontend\\Page\\PageRepository');
            }
        }

        return $this->pageRepository;
    }

    /**
     * Get TYPO3s Database Connection
     *
     * @return \TYPO3\CMS\Core\Database\DatabaseConnection
     */
    protected function getDatabaseConnection()
    {
        return $GLOBALS['TYPO3_DB'];
    }

    /**
     * Get TYPO3s TCA table configuration array
     *
     * @return array
     */
    protected function getTCA()
    {
        return $GLOBALS['TCA'];
    }

    /**
     * Gets the l10n_mode for the record from tca configuration
     *
     * @var array $record the sysFileReference record
     * @return string
     */
    protected function getL10nMode($record)
    {
        $table = $record['tablenames'];
        $fieldName = $record['fieldname'];
        $tca = $this->getTCA();

        return $tca[$table]['columns'][$fieldName]['l10n_mode'] ?? '';
    }
}
