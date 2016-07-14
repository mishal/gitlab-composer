<?php

namespace ExtendedGitlab;

use Gitlab\Api\Projects as BaseProjects;

class Projects extends BaseProjects
{
    public function archive($projectId, $sha = null)
    {
        $result = $this->get('projects/'.$this->encodePath($projectId).'/repository/archive', [
            'sha' => $sha,
        ]);

        return $result;
    }
}
