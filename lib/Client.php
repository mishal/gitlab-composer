<?php

namespace ExtendedGitlab;

use Gitlab\Client as BaseClient;

class Client extends BaseClient
{
    public function api($name)
    {
        switch ($name) {
            case 'projects':
                return new Projects($this);
            default:
                parent::api($name);
        }
    }

}
