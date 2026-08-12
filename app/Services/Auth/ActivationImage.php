<?php

namespace App\Services\Auth;

use App\Models\User;
use BaconQrCode\Renderer\Image\ImagickImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use RuntimeException;

/**
 * Renders the activation image — adr/0004 decision 7, auth-rbac.spec.md BR-A21.
 *
 * ⚠ THREE ELEMENTS, NOT ONE. The image carries the QR code, the employee's FULL NAME, and the
 * VALIDITY PERIOD, and the two text lines are not decoration.
 *
 * HR forwards this over WhatsApp, so it arrives detached from whatever HR typed alongside it,
 * in a thread that may hold several of them. Without the name, HR cannot tell two activations
 * apart and sends the wrong one — which hands one employee's account to another, with the
 * audit log showing the wrong person from that moment on. Without the validity period, an
 * employee scans a dead token days later and reads "not valid" with no idea why, and HR has
 * no way to see it coming.
 *
 * ⚠ THE IMAGE IS A CREDENTIAL. Whoever holds it can activate the account: redemption
 * authenticates the holder outright and lets them set the first password. It is served only
 * to `HR` and Master Admin, and never stored on disk — generated per request and streamed.
 */
class ActivationImage
{
    private const CANVAS_WIDTH = 520;

    private const QR_SIZE = 380;

    private const PADDING = 40;

    /**
     * The PNG bytes for this account's live token.
     *
     * @throws RuntimeException when the account has no token to render
     */
    public function render(User $user): string
    {
        if ($user->activation_token === null || $user->activation_expires_at === null) {
            // Rendering a QR for a token that does not exist would produce an image that
            // looks exactly like a working one and fails on scan, days later, in front of
            // the employee.
            throw new RuntimeException(
                "Account {$user->getKey()} has no activation token to render. Generate one first "
                .'(auth-rbac.spec.md §5.6).'
            );
        }

        $qr = $this->qrCode(route('activation.redeem', ['token' => $user->activation_token]));

        return $this->compose(
            $qr,
            $user->employee?->full_name ?? $user->name,
            $user->activation_expires_at,
        );
    }

    /** @return \GdImage */
    private function qrCode(string $url)
    {
        // Imagick backend because bacon 3.x ships no GD backend; the PNG it returns is then
        // loaded into GD, which is what has FreeType for the text below.
        $writer = new Writer(new ImageRenderer(
            new RendererStyle(self::QR_SIZE, 1),
            new ImagickImageBackEnd(),
        ));

        $image = imagecreatefromstring($writer->writeString($url));

        if ($image === false) {
            throw new RuntimeException('The QR renderer produced bytes GD could not read.');
        }

        return $image;
    }

    /**
     * @param  \GdImage  $qr
     */
    private function compose($qr, string $fullName, \DateTimeInterface $expiresAt): string
    {
        $height = self::PADDING + self::QR_SIZE + 100;
        $canvas = imagecreatetruecolor(self::CANVAS_WIDTH, $height);

        $white = imagecolorallocate($canvas, 255, 255, 255);
        $ink = imagecolorallocate($canvas, 15, 23, 42);
        $muted = imagecolorallocate($canvas, 100, 116, 139);

        imagefilledrectangle($canvas, 0, 0, self::CANVAS_WIDTH, $height, $white);

        imagecopy(
            $canvas, $qr,
            (int) ((self::CANVAS_WIDTH - self::QR_SIZE) / 2), self::PADDING,
            0, 0, self::QR_SIZE, self::QR_SIZE,
        );

        imagedestroy($qr);

        // ⚠ Bold for the name — it is the line a recipient checks first to see whose
        // activation this is, and the whole reason the name is on the image.
        $this->centredText($canvas, $fullName, $this->font('Bold'), 20, self::PADDING + self::QR_SIZE + 45, $ink);

        $this->centredText(
            $canvas,
            'Valid until '.$expiresAt->format('j M Y, g:ia'),
            $this->font('Regular'),
            13,
            self::PADDING + self::QR_SIZE + 78,
            $muted,
        );

        ob_start();
        imagepng($canvas);
        $png = (string) ob_get_clean();

        imagedestroy($canvas);

        return $png;
    }

    /** @param  \GdImage  $canvas */
    private function centredText($canvas, string $text, string $font, int $size, int $y, int $colour): void
    {
        $box = imagettfbbox($size, 0, $font, $text);

        if ($box === false) {
            throw new RuntimeException("Could not measure text with the font at {$font}.");
        }

        $x = (int) ((self::CANVAS_WIDTH - ($box[2] - $box[0])) / 2);

        imagettftext($canvas, $size, 0, $x, $y, $colour, $font, $text);
    }

    /**
     * ⚠ Vendored in the repository, not taken from the system.
     *
     * The Sail container has Liberation and DejaVu; the production VPS is a native LEMP stack
     * and may not. A missing font is not cosmetic — imagettftext() fails, no image can be
     * produced, and HR cannot activate anybody. See resources/fonts/README.md.
     */
    private function font(string $weight): string
    {
        $path = resource_path("fonts/LiberationSans-{$weight}.ttf");

        if (! is_file($path)) {
            throw new RuntimeException(
                "The activation image font is missing: {$path}. It is vendored in the "
                .'repository precisely so this cannot depend on what the server happens to '
                .'have installed (resources/fonts/README.md).'
            );
        }

        return $path;
    }
}
