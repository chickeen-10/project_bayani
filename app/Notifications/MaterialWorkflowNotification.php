<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class MaterialWorkflowNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $action,
        private readonly int $materialId,
        private readonly string $materialTitle,
        private readonly ?string $remarks = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $messages = [
            'submitted' => 'A material was submitted for review.',
            'resubmitted' => 'An approved material was changed and returned to review.',
            'approved' => 'Your material was approved and published.',
            'revision_requested' => 'Revisions were requested for your material.',
            'rejected' => 'Your material was rejected.',
        ];

        return [
            'action' => $this->action,
            'material_id' => $this->materialId,
            'title' => $this->materialTitle,
            'message' => $messages[$this->action] ?? 'There is an update to your material.',
            'remarks' => $this->remarks,
            'url' => in_array($this->action, ['approved', 'revision_requested', 'rejected', 'resubmitted'], true)
                ? '/contributor/submissions/'.$this->materialId
                : '/admin/review/'.$this->materialId,
        ];
    }
}
