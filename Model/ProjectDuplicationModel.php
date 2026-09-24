<?php

namespace Kanboard\Plugin\Discord\Model;

/**
 * Extends project duplication so plugin-owned Discord project settings follow
 * the existing Kanboard metadata duplication selection.
 */
class ProjectDuplicationModel extends \Kanboard\Model\ProjectDuplicationModel
{
    /**
     * @param integer $src_project_id
     * @param array   $selection
     * @param integer $owner_id
     * @param string  $name
     * @param boolean $private
     * @param string  $identifier
     * @return integer|false
     */
    public function duplicate($src_project_id, $selection = array('projectPermissionModel', 'categoryModel', 'actionModel'), $owner_id = 0, $name = null, $private = null, $identifier = null)
    {
        if (in_array('projectMetadataModel', $selection, true) && ! in_array('discordSettingsModel', $selection, true)) {
            $selection[] = 'discordSettingsModel';
        }

        return parent::duplicate($src_project_id, $selection, $owner_id, $name, $private, $identifier);
    }

    /**
     * @return string[]
     */
    public function getOptionalSelection()
    {
        $selection = parent::getOptionalSelection();
        $selection[] = 'discordSettingsModel';
        return array_values(array_unique($selection));
    }

    /**
     * @return string[]
     */
    public function getPossibleSelection()
    {
        $selection = parent::getPossibleSelection();
        $selection[] = 'discordSettingsModel';
        return array_values(array_unique($selection));
    }
}
