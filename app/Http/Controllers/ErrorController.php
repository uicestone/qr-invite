<?php namespace App\Http\Controllers;

use App\Http\Request;
use Log;

class ErrorController extends Controller
{
	/**
	 * @param Request $request
	 */
	public function log(Request $request)
	{
		$data = $request->data();
		Log::warning('JavaScript Error: ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ' ' . $request->ip() . ' ' . $request->server('HTTP_USER_AGENT'));
	}
}
