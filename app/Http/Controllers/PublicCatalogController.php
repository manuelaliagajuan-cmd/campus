<?php

namespace App\Http\Controllers;

use App\Services\StoreCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class PublicCatalogController extends Controller
{
    public function catalog(Request $r)
    {
        try {
            $items = array_map(function ($p) {
                $row = ['key' => $p['key'], 'kind' => $p['kind'], 'name' => $p['name'], 'amount' => $p['amount'], 'url' => url('/comprar/desde-web').'?producto='.rawurlencode($p['key'])];
                if ($p['kind'] === 'pass') {
                    $row += array_intersect_key($p, array_flip(['sessions', 'days', 'minutes', 'online_enabled']));
                } else {
                    $row['description'] = $p['description'];
                }

                return $row;
            }, array_values(StoreCatalog::all()));
            $response = response()->json(['items' => $items, 'enabled' => StoreCatalog::enabled()]);
        } catch (\Throwable $e) {
            report($e);
            $response = response()->json(['unavailable' => true], 503);
        }
        $origin = $r->header('Origin');
        if (in_array($origin, ['https://cecalicante.es', 'https://www.cecalicante.es'], true)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
        }
        $response->headers->set('Vary', 'Origin');
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    public function enter(Request $r)
    {
        $d = $r->validate(['producto' => 'nullable|string|max:120']);
        if (! empty($d['producto'])) {
            abort_unless(isset(StoreCatalog::all()[$d['producto']]), 404);
        }
        $url = URL::temporarySignedRoute('store.index', now()->addHour(), ['origin' => 'web', 'producto' => $d['producto'] ?? '']);
        if (! $r->user()) {
            $r->session()->put('url.intended', $url);

            return redirect()->route('login');
        }

        return redirect()->to($url);
    }
}
