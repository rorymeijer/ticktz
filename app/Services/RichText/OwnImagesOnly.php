<?php

declare(strict_types=1);

namespace App\Services\RichText;

use App\Models\RichTextImage;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * Refuses an image that is not ours.
 *
 * A remote `<img>` is a tracking pixel. Whoever controls the URL learns the IP
 * address, the rough time and the user agent of every person who opens the
 * ticket — and on a service desk that is an agent, their team lead, and anyone
 * the ticket is escalated to. The requester needs no account and no cleverness
 * to plant one; they need a mail client that pastes a signature.
 *
 * The brief this was built to says no third-party trackers. Honouring that
 * only for the trackers we ship, and not for the ones our own input field
 * accepts, would be honouring it in the wrong direction.
 *
 * So an image in a message may only point at an image this instance is
 * serving. Anything else is dropped — and dropped rather than escaped, so a
 * reply pasted out of a mail client keeps its words and loses the pixel.
 *
 * Articles keep the wider rule. They are written by staff, on purpose, and an
 * administrator embedding a diagram from their own intranet is a different act
 * from a stranger's signature arriving in a ticket. That difference is the
 * profile's, so this sanitiser is only attached to the message one.
 */
final class OwnImagesOnly implements AttributeSanitizerInterface
{
    /** @return list<string> */
    public function getSupportedElements(): array
    {
        return ['img'];
    }

    /** @return list<string> */
    public function getSupportedAttributes(): array
    {
        return ['src'];
    }

    public function sanitizeAttribute(
        string $element,
        string $attribute,
        string $value,
        HtmlSanitizerConfig $config,
    ): ?string {
        // Returning null drops the attribute, and an <img> with no src is
        // removed by the visitor that follows.
        return RichTextImage::isOwnUrl($value) ? $value : null;
    }
}
