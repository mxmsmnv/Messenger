<?php namespace ProcessWire;

class ProcessMessengerWorkspace extends Process
{
    public static function getModuleInfo(): array
    {
        return [
            'title' => 'Messages',
            'summary' => 'Private messages in Workspace.',
            'version' => 100,
            'requires' => ['Messenger', 'Workspace'],
            'permission' => 'workspace-use',
            'icon' => 'comments',
            'page' => ['name' => 'messages', 'title' => 'Messages'],
        ];
    }

    public function ___execute(): string
    {
        $actor = $this->wire('user');
        $messenger = $this->wire('modules')->get('Messenger');
        if (!$messenger->workspaceMembersOnly() || !$messenger->canUse($actor)) {
            throw new WirePermissionException('Messaging is unavailable.');
        }

        header('Cache-Control: private, no-store');
        $this->headline('Messages');
        $api = $this->wire('config')->urls->root . 'messenger-api/' . Messenger::REST_API_VERSION . '/';

        return $messenger->renderApp($actor, ['title' => 'Messages', 'api' => $api]);
    }
}
