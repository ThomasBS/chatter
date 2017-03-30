<?php

namespace DevDojo\Chatter\Events;

use DevDojo\Chatter\Models\Discussion;

class ChatterAfterNewDiscussion
{
    /**
     * @var Discussion
     */
    public $discussion;

    /**
     * Constructor.
     *
     * @param Discussion $discussion
     */
    public function __construct(Discussion $discussion)
    {
        $this->discussion = $discussion;
    }
}
