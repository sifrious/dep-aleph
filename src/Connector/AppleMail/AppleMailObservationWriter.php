<?php

declare(strict_types=1);

namespace Sifrious\Aleph\Connector\AppleMail;

interface AppleMailObservationWriter
{
    public function writeMessage(AppleMailMessageSubmission $submission, string $attemptId): string;

    public function writeAttachment(AppleMailAttachmentSubmission $submission, string $attemptId): string;
}
