<?php

/*
 * Laravel Boost merges this file over its own defaults, so only the
 * settings that differ from them belong here.
 */
return [

    /*
     * Write skills straight into the committed .ai/skills folder.
     * .claude/skills is a symlink to it, so Claude Code reads the same files.
     */
    'agents' => [
        'claude_code' => [
            'skills_path' => '.ai/skills',
        ],
    ],

];
