<?php

/*
 * Copyright (c) 2018 Heimrich & Hannot GmbH
 *
 * @license LGPL-3.0-or-later
 */

namespace HeimrichHannot\JobBundle\DataContainer;

use Contao\Backend;
use Contao\BackendUser;
use Contao\Controller;
use Contao\CoreBundle\Exception\AccessDeniedException;
use Contao\Database;
use Contao\DataContainer;
use Contao\Input;
use Contao\System;
use HeimrichHannot\JobBundle\Model\JobModel;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

class Job extends Backend
{
    /**
     * @var ContainerInterface
     */
    protected $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        parent::__construct();
    }

    /**
     * Set the timestamp to 00:00:00
     *
     * @param integer $value
     *
     * @return integer
     */
    public function loadDate($value)
    {
        return strtotime(date('Y-m-d', $value ?: time()) . ' 00:00:00');
    }

    /**
     * Set the timestamp to 1970-01-01
     *
     * @param integer $value
     *
     * @return integer
     */
    public function loadTime($value)
    {
        return strtotime('1970-01-01 ' . date('H:i:s', $value ?: time()));
    }

    /**
     * Adjust start end end time of the item
     *
     * @param DataContainer $dc
     */
    public function adjustTime(DataContainer $dc)
    {
        // Return if there is no active record (override all)
        if (!$dc->activeRecord) {
            return;
        }

        /** @var JobModel $adapter */
        $adapter = $this->container->get('contao.framework')->getAdapter(JobModel::class);

        if (null !== ($model = $adapter->findByPk($dc->id))) {
            $model->date = strtotime(date('Y-m-d', $dc->activeRecord->date) . ' ' . date('H:i:s', $dc->activeRecord->time));
            $model->time = $model->date;
            $model->save();
        }
    }

    public function getUploadPath($target, $file, DataContainer $dc)
    {
        return 'files/jobs';
    }

    public function listChildren($arrRow)
    {
        return '<div class="tl_content_left">' . ($arrRow['title'] ?: $arrRow['id']) . ' <span style="color:#b3b3b3; padding-left:3px">[' . \Date::parse(\Contao\Config::get('datimFormat'), trim($arrRow['date'])) . ']</span></div>';
    }

    public function checkPermission()
    {
        $user     = BackendUser::getInstance();
        $database = Database::getInstance();

        if ($user->isAdmin) {
            return;
        }

        // Set the root IDs
        if (!is_array($user->jobs) || empty($user->jobs)) {
            $root = [0];
        } else {
            $root = $user->jobs;
        }

        $id = strlen(Input::get('id')) ? Input::get('id') : CURRENT_ID;

        // Check current action
        switch (Input::get('act')) {
            case 'paste':
                // Allow
                break;

            case 'create':
                if (!strlen(Input::get('pid')) || !in_array(Input::get('pid'), $root)) {
                    throw new AccessDeniedException('Not enough permissions to create job items in job archive ID ' . Input::get('pid') . '.');
                }
                break;

            case 'cut':
            case 'copy':
            case 'edit':
            case 'show':
            case 'delete':
            case 'toggle':
            case 'feature':
                $item = \Contao\System::getContainer()->get('huh.utils.model')->findModelInstanceByPk('tl_job', $id);

                if (null === $item) {
                    throw new AccessDeniedException('Invalid job item ID ' . $id . '.');
                }

                if (!in_array($item->pid, $root)) {
                    throw new AccessDeniedException('Not enough permissions to ' . Input::get('act') . ' job item ID ' . $id . ' of job archive ID ' . $job->pid . '.');
                }
                break;

            case 'select':
            case 'editAll':
            case 'deleteAll':
            case 'overrideAll':
            case 'cutAll':
            case 'copyAll':

                if (!\in_array($id, $root)) {
                    throw new AccessDeniedException('Not enough permissions to access tl_job archive ID ' . $id . '.');
                }

                $items = \Contao\System::getContainer()->get('huh.utils.model')->findModelInstancesBy('tl_job', ['pid=?'], [$id]);


                if (null === $items) {
                    break;
                }

                /** @var SessionInterface $objSession */
                $session = System::getContainer()->get('session');

                $sessionData                   = $objSession->all();
                $sessionData['CURRENT']['IDS'] = array_intersect((array)$sessionData['CURRENT']['IDS'], $items->fetchEach('id'));
                $session->replace($sessionData);
                break;

            default:
                if (strlen(Input::get('act'))) {
                    throw new AccessDeniedException('Invalid command "' . Input::get('act') . '".');
                } elseif (!in_array($id, $root)) {
                    throw newAccessDeniedException('Not enough permissions to access job archive ID ' . $id . '.');
                }
                break;
        }
    }

    public function toggleIcon($row, $href, $label, $title, $icon, $attributes)
    {
        $user = BackendUser::getInstance();

        if (strlen(Input::get('tid'))) {
            $this->toggleVisibility(Input::get('tid'), ('1' === Input::get('state')), (@func_get_arg(12) ?: null));
            Controller::redirect(System::getReferer());
        }

        // Check permissions AFTER checking the tid, so hacking attempts are logged
        if (!$user->hasAccess('tl_job::published', 'alexf')) {
            return '';
        }

        $href .= '&amp;tid=' . $row['id'] . '&amp;state=' . ($row['published'] ? '' : 1);

        if (!$row['published']) {
            $icon = 'invisible.svg';
        }

        return '<a href="' . Controller::addToUrl($href) . '&rt=' . \RequestToken::get() . '" title="' . \StringUtil::specialchars($title) . '"' . $attributes . '>' . \Image::getHtml($icon, $label, 'data-state="' . ($row['published'] ? 1 : 0) . '"') . '</a> ';
    }

    public function toggleVisibility($intId, $blnVisible, \DataContainer $dc = null)
    {
        $user     = BackendUser::getInstance();
        $database = Database::getInstance();

        // Set the ID and action
        Input::setGet('id', $intId);
        Input::setGet('act', 'toggle');

        if ($dc) {
            $dc->id = $intId; // see #8043
        }

        // Trigger the onload_callback
        if (is_array($GLOBALS['TL_DCA']['tl_job']['config']['onload_callback'])) {
            foreach ($GLOBALS['TL_DCA']['tl_job']['config']['onload_callback'] as $callback) {
                if (is_array($callback)) {
                    System::importStatic($callback[0])->{$callback[1]}($dc);
                } elseif (is_callable($callback)) {
                    $callback($dc);
                }
            }
        }

        // Check the field access
        if (!$user->hasAccess('tl_job::published', 'alexf')) {
            throw new AccessDeniedException('Not enough permissions to publish/unpublish job item ID ' . $intId . '.');
        }

        // Set the current record
        if ($dc) {
            $objRow = $database->prepare('SELECT * FROM tl_job WHERE id=?')->limit(1)->execute($intId);

            if ($objRow->numRows) {
                $dc->activeRecord = $objRow;
            }
        }

        $objVersions = new \Versions('tl_job', $intId);
        $objVersions->initialize();

        // Trigger the save_callback
        if (is_array($GLOBALS['TL_DCA']['tl_job']['fields']['published']['save_callback'])) {
            foreach ($GLOBALS['TL_DCA']['tl_job']['fields']['published']['save_callback'] as $callback) {
                if (is_array($callback)) {
                    $blnVisible = System::importStatic($callback[0])->{$callback[1]}($blnVisible, $dc);
                } elseif (is_callable($callback)) {
                    $blnVisible = $callback($blnVisible, $dc);
                }
            }
        }

        $time = time();

        // Update the database
        $database->prepare("UPDATE tl_job SET tstamp=$time, published='" . ($blnVisible ? '1' : "''") . "' WHERE id=?")->execute($intId);

        if ($dc) {
            $dc->activeRecord->tstamp    = $time;
            $dc->activeRecord->published = ($blnVisible ? '1' : '');
        }

        // Trigger the onsubmit_callback
        if (is_array($GLOBALS['TL_DCA']['tl_job']['config']['onsubmit_callback'])) {
            foreach ($GLOBALS['TL_DCA']['tl_job']['config']['onsubmit_callback'] as $callback) {
                if (is_array($callback)) {
                    System::importStatic($callback[0])->{$callback[1]}($dc);
                } elseif (is_callable($callback)) {
                    $callback($dc);
                }
            }
        }

        $objVersions->create();
    }
}