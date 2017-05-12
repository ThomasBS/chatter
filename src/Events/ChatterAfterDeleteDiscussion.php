<?php

namespace DevDojo\Chatter\Events;

use Illuminate\Http\Request;

class ChatterAfterDeleteDiscussion
{
    /**
     * @var int
     */
    public $discussionId;

    /**
     * Constructor.
     *
     * @param int $discussionId
     */
    public function __construct($discussionId)
    {
        $this->discussionId = $discussionId;
    }
}
