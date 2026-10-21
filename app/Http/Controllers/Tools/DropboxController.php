<?php

namespace App\Http\Controllers\Tools;

use App\Clients\DropboxClient;
use App\Exceptions\DropboxException;
use App\Http\Controllers\Controller;
use App\Services\DropboxTokenStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Tools → Dropbox: connects the Dropbox account the MCP server takes product
 * photos from. The admin is sent to Dropbox's consent page and comes back on
 * callback(); the tokens are stored in Redis ({@see DropboxTokenStore}).
 */
class DropboxController extends Controller
{
    private const STATE_SESSION_KEY = 'dropbox_oauth_state';

    public function __construct(private DropboxClient $dropbox, private DropboxTokenStore $tokens) {}

    public function index(): View
    {
        return view('admin::tools.dropbox', [
            'hasAppCredentials' => $this->dropbox->hasAppCredentials(),
            'connection'        => $this->tokens->connection(),
            'redirectUri'       => $this->redirectUri(),
        ]);
    }

    public function connect(Request $request): RedirectResponse
    {
        if (! $this->dropbox->hasAppCredentials()) {
            return $this->back('error', 'Stel eerst DROPBOX_APP_KEY en DROPBOX_APP_SECRET in.');
        }

        $state = Str::random(40);
        $request->session()->put(self::STATE_SESSION_KEY, $state);

        return redirect()->away($this->dropbox->authorizeUrl($this->redirectUri(), $state));
    }

    public function callback(Request $request): RedirectResponse
    {
        $expectedState = $request->session()->pull(self::STATE_SESSION_KEY);

        if (! is_string($expectedState) || ! hash_equals($expectedState, (string) $request->query('state'))) {
            return $this->back('error', 'De koppeling is verlopen of niet vanuit deze pagina gestart. Probeer het opnieuw.');
        }

        if ($request->filled('error')) {
            return $this->back('error', 'Dropbox heeft geen toegang gegeven: '.($request->query('error_description') ?: $request->query('error')));
        }

        try {
            $details = $this->dropbox->connect((string) $request->query('code'), $this->redirectUri(), auth()->guard('admin')->user()?->email);
        } catch (DropboxException $e) {
            return $this->back('error', $e->getMessage());
        }

        Log::channel('mcp')->info('dropbox connected', ['account' => $details['account_email'], 'by' => $details['connected_by']]);

        return $this->back('success', 'Dropbox is gekoppeld met '.($details['account_email'] ?? $details['account_name']).'.');
    }

    public function disconnect(): RedirectResponse
    {
        $this->dropbox->disconnect();

        Log::channel('mcp')->info('dropbox disconnected', ['by' => auth()->guard('admin')->user()?->email]);

        return $this->back('success', 'Dropbox is ontkoppeld.');
    }

    private function redirectUri(): string
    {
        return route('admin.tools.dropbox.callback');
    }

    private function back(string $type, string $message): RedirectResponse
    {
        session()->flash($type, $message);

        return redirect()->route('admin.tools.dropbox.index');
    }
}
