<?php

namespace Kanboard\Plugin\Discord\Model;

/**
 * Subtask model override used only to preserve previous values in clean Kanboard
 * cores before dispatching subtask update notifications.
 */
class SubtaskModel extends \Kanboard\Model\SubtaskModel
{
    /**
     * Update a subtask and pass the previous row to the event builder.
     *
     * Kanboard core dispatches subtask.update after persisting the new row and
     * passes only submitted values. At that point the stock event builder cannot
     * compute which subtask fields changed. The Discord plugin needs that diff
     * for per-field notification rules, so the plugin-level override preserves
     * the previous row without patching Kanboard core.
     *
     * @access public
     * @param  array $values
     * @param  bool  $fireEvent
     * @return bool
     */
    public function update(array $values, $fireEvent = true)
    {
        $this->prepare($values);
        $previousSubtask = $this->getById($values['id']);
        $updates = $values;
        unset($updates['id']);
        $result = $this->db->table(self::TABLE)->eq('id', $values['id'])->save($updates);

        if ($result) {
            $subtask = $this->getById($values['id']);
            $this->subtaskTimeTrackingModel->updateTaskTimeTracking($subtask['task_id']);

            if ($fireEvent) {
                $this->queueManager->push($this->subtaskEventJob->withParams(
                    $subtask['id'],
                    array(self::EVENT_CREATE_UPDATE, self::EVENT_UPDATE),
                    $previousSubtask
                ));
            }
        }

        return $result;
    }
}
