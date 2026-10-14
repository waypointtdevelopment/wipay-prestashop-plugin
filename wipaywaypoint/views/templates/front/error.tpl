{**
 * Standalone page shown when a WiPay checkout cannot be started or verified.
 * It deliberately does not extend the theme's page template so it renders on
 * any theme, including themes that have overridden the core checkout pages.
 *}
<!doctype html>
<html lang="{$language.iso_code|escape:'html':'UTF-8'}">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title>{$wipay_error_title|escape:'html':'UTF-8'}</title>
	<style>
		body { margin: 0; padding: 2rem 1rem; background: #f6f7f9; color: #1a1a1a;
		       font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; }
		.wipay-box { max-width: 40rem; margin: 0 auto; background: #fff; border: 1px solid #e2e4e8;
		             border-radius: .5rem; padding: 1.75rem; }
		.wipay-box h1 { margin: 0 0 .75rem; font-size: 1.25rem; line-height: 1.4; }
		.wipay-box p { margin: 0 0 1rem; line-height: 1.6; }
		.wipay-box .wipay-meta { color: #5a6070; font-size: .9rem; }
		.wipay-box .wipay-action { display: inline-block; margin-top: .5rem; padding: .65rem 1.15rem;
		                           background: #1f6feb; color: #fff; text-decoration: none; border-radius: .35rem; }
	</style>
</head>
<body>
	<div class="wipay-box">
		<h1>{$wipay_error_title|escape:'html':'UTF-8'}</h1>
		<p>{$wipay_error|escape:'html':'UTF-8'}</p>
		{if $wipay_error_details}
			<p class="wipay-meta">{$wipay_error_details|escape:'html':'UTF-8'}</p>
		{/if}
		{if $wipay_back_url}
			<a class="wipay-action" href="{$wipay_back_url|escape:'html':'UTF-8'}">{$wipay_back_label|escape:'html':'UTF-8'}</a>
		{/if}
	</div>
</body>
</html>
