<?php

declare(strict_types=1);

namespace Maildeno;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * NativeEngine — the render engine. Drives a platform-native maildeno-engine
 * executable, bundled with this package under bin/<platform>/. This is the
 * default and only engine MaildenoClient uses; you don't need to configure
 * or construct it yourself for normal use — see NativeEngine::locate().
 *
 * Protocol with the child process: the input-JSON envelope
 * (`{template, target, dynamic_data}`) is written to its STDIN. On success
 * (exit code 0) it writes the rendered output directly to STDOUT — plain
 * HTML / TSX / MJML text, not JSON — which is returned as-is. On failure
 * (non-zero exit) it writes a message to STDERR, which becomes the thrown
 * MaildenoError's message; STDOUT is not used for errors.
 *
 * ── Why Symfony Process instead of a hand-rolled proc_open() ──────────────
 * A naive proc_open() that writes all of STDIN then reads all of STDOUT can
 * deadlock once the payload exceeds the OS pipe buffer (~64KB on Linux): the
 * parent blocks writing to a full STDIN pipe while the child blocks writing
 * to a full STDOUT pipe nobody is draining yet — a real risk for anything
 * beyond a short template. stream_select() fixes this on Unix but doesn't
 * behave the same way against proc_open pipes on Windows — a long-standing
 * PHP limitation. Symfony Process implements the correct platform-specific
 * version of this and is small and extremely widely used (most PHP installs
 * already pull it in transitively) — a real `require`, not a suggestion,
 * since it's needed for every render.
 *
 * ── Deployment notes ───────────────────────────────────────────────────────
 * - The binary must be executable. Composer/zip/tar extraction does not
 *   always preserve the Unix executable bit — the constructor repairs it
 *   defensively with chmod(0755) if needed.
 * - Apple Silicon (macOS arm64) refuses to run ANY Mach-O binary that isn't
 *   at least ad-hoc code-signed. If you cross-compile macos-arm64 from
 *   Linux CI, run `codesign -s -` as a build step or it will not launch on
 *   the target machine at all.
 * - Linux binaries should be built against musl (`*-unknown-linux-musl`
 *   Rust targets), statically linked. A glibc build will not run on
 *   musl-based images (Alpine — extremely common for PHP containers), while
 *   a static musl binary runs on both.
 * - Some locked-down shared hosts disable `proc_open`/`proc_close` via
 *   `disable_functions` — check before deploying if renders fail with
 *   "failed to start" errors.
 */
final class NativeEngine implements RenderEngine
{
    public function __construct(
        private readonly string $binaryPath,
        private readonly ?float $timeoutSeconds = 30.0,
    ) {
        if (!\class_exists(Process::class)) {
            // Should not happen — symfony/process is a hard `require` of this
            // package — but fail clearly rather than with a class-not-found.
            throw new MaildenoError(
                'RENDER_ERROR',
                'NativeEngine requires symfony/process, which should have been installed '
                . 'automatically with this package. Try: composer require symfony/process',
            );
        }
        if (!\is_file($binaryPath)) {
            throw new MaildenoError('RENDER_ERROR', "maildeno-engine binary not found at {$binaryPath}");
        }
        if (!\is_executable($binaryPath)) {
            @\chmod($binaryPath, 0755);
            if (!\is_executable($binaryPath)) {
                throw new MaildenoError(
                    'RENDER_ERROR',
                    "maildeno-engine at {$binaryPath} is not executable (chmod +x it).",
                );
            }
        }
    }

    /**
     * Resolve, verify, and return the path to the correct maildeno-engine
     * binary for the current OS/architecture.
     *
     * Called with no argument, this resolves against the bin/ directory
     * bundled with this package itself (wherever it's installed —
     * vendor/maildeno/maildeno/bin, a git checkout, etc.) — this is what
     * makes the SDK work with zero engine configuration out of the box.
     * Pass an explicit $binDir only if you're shipping the binaries
     * somewhere non-standard.
     *
     * Expected layout under $binDir:
     *   windows-x64/engine.exe
     *   linux-x64/engine    (static musl build — see class docblock)
     *   linux-arm64/engine  (static musl build)
     *   macos-x64/engine
     *   macos-arm64/engine  (ad-hoc code-signed — see class docblock)
     *
     * @throws MaildenoError if the platform is unsupported, or the binary
     *                       for it is missing from $binDir.
     */
    public static function locate(?string $binDir = null): string
    {
        $binDir ??= \dirname(__DIR__) . '/bin';
        $binDir = \rtrim($binDir, '/\\');
        $arch   = \strtolower((string) \php_uname('m'));
        $isArm  = \str_contains($arch, 'arm') || \str_contains($arch, 'aarch64');

        $path = match (PHP_OS_FAMILY) {
            'Windows' => "{$binDir}/windows-x64/engine.exe",
            'Linux'   => $isArm
                ? "{$binDir}/linux-arm64/engine"
                : "{$binDir}/linux-x64/engine",
            'Darwin'  => $isArm
                ? "{$binDir}/macos-arm64/engine"
                : "{$binDir}/macos-x64/engine",
            default   => throw new MaildenoError(
                'RENDER_ERROR',
                'Unsupported operating system: ' . PHP_OS_FAMILY,
            ),
        };

        if (!\is_file($path)) {
            throw new MaildenoError(
                'RENDER_ERROR',
                "maildeno-engine binary not found at {$path} (platform: " . PHP_OS_FAMILY . '/' . $arch . '). '
                . 'If you installed via Composer, this should have been bundled — try reinstalling. '
                . 'If you\'re on a platform without a prebuilt binary yet, pass a custom enginePath, '
                . 'or build one from the maildeno-engine-cli project and drop it in.',
            );
        }

        return $path;
    }

    public function renderTemplate(array $template, string $target, ?array $dynamicData = null): string
    {
        $payload = \json_encode([
            'template'     => $template,
            'target'       => $target,
            'dynamic_data' => ($dynamicData === null || $dynamicData === [])
                ? new \stdClass()
                : $dynamicData,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return $this->renderRawResult($payload);
    }

    /**
     * Low-level: exact input-envelope JSON in, exact rendered output string
     * out (already unwrapped — not JSON). Throws MaildenoError on failure.
     */
    public function renderRawResult(string $inputJson): string
    {
        // Adjust this argv list to match your Rust CLI's parsing — this
        // assumes the binary always reads the envelope from STDIN with no
        // subcommand needed. Add one (e.g. [$this->binaryPath, 'render']) if
        // your main() dispatches on argv[1] instead.
        $process = new Process([$this->binaryPath]);
        $process->setInput($inputJson);
        $process->setTimeout($this->timeoutSeconds);

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            throw new MaildenoError('RENDER_ERROR', 'maildeno-engine timed out: ' . $e->getMessage());
        }

        if (!$process->isSuccessful()) {
            $stderr = \trim($process->getErrorOutput());
            $detail = $stderr !== '' ? $stderr : "exit code {$process->getExitCode()}";
            throw new MaildenoError('RENDER_ERROR', "maildeno-engine failed: {$detail}");
        }

        return $process->getOutput();
    }
}
