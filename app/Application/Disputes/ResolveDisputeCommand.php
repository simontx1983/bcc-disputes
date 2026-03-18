<?php

namespace BCC\Disputes\Application\Disputes;

if (!defined('ABSPATH')) {
    exit;
}

final class ResolveDisputeCommand
{
    public int $disputeId;
    public int $voteId;
    public int $pageId;
    public int $voterId;
    public int $reporterId;
    public string $outcome;

    public function __construct(
        int $disputeId,
        int $voteId,
        int $pageId,
        int $voterId,
        int $reporterId,
        string $outcome
    ) {
        $this->disputeId  = $disputeId;
        $this->voteId     = $voteId;
        $this->pageId     = $pageId;
        $this->voterId    = $voterId;
        $this->reporterId = $reporterId;
        $this->outcome    = $outcome;
    }
}
