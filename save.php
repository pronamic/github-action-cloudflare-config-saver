<?php

/**
 * Functions.
 */
function escape_sequence( $code ) {
	return "\e[" . $code . 'm';
}

function format_command( $value ) {
	return escape_sequence( '36' ) . $value . escape_sequence( '0' );
}

function format_error( $value ) {
	return escape_sequence( '31' ) . escape_sequence( '1' ) . 'Error:' . escape_sequence( '0' ) . ' ' . $value;
}

function run_command( $command, $expected_result_code = 0 ) {
	echo format_command( $command ), PHP_EOL;

	passthru( $command, $result_code );

	if ( null !== $expected_result_code && $expected_result_code !== $result_code ) {
		exit( $result_code );
	}

	return $result_code;
}

function run_shell_exec( $command ) {
	echo format_command( $command ), PHP_EOL;

    return shell_exec( $command );
}

function start_group( $name ) {
	echo '::group::', $name, PHP_EOL;
}

function end_group() {
	echo '::endgroup::', PHP_EOL;
}

/**
 * Get input.
 *
 * @link https://docs.github.com/en/actions/creating-actions/metadata-syntax-for-github-actions#inputs
 * @link https://docs.github.com/en/actions/using-workflows/workflow-syntax-for-github-actions#jobsjob_idstepswith
 * @link https://github.com/actions/checkout/blob/cd7d8d697e10461458bc61a30d094dc601a8b017/dist/index.js#L2699-L2717
 * @param string $name
 * @return string|array|false
 */
function get_input( $name ) {
	$env_name = 'INPUT_' . strtoupper( $name );

	return getenv( $env_name );
}

function get_required_input( $name ) {
	$value = get_input( $name );

	if ( false === $value || '' === $value ) {
		echo format_error( escape_sequence( '90' ) . 'Input required and not supplied:' . escape_sequence( '0' ) . ' ' . $name );

		exit( 1 );
	}

	return $value;
}

/**
 * Cloudflare API.
 *
 * @param string $url       URL.
 * @param string $api_token API token.
 */
function cloudflare_api( $url, $api_token ) {
	$command = <<<EOT
	curl --request GET --fail --silent $url \
		--header "Authorization: Bearer $api_token" \
		--header "Content-Type: application/json"
	EOT;

	$response = run_shell_exec( $command );

	if ( null === $response ) {
		echo format_error( 'Cloudflare API request failed' );

		exit( 1 );
	}

	return $response;
}

/**
 * Setup.
 */
$api_token = get_required_input( 'api_token' );
$zone_id   = get_required_input( 'zone_id' );
$directory = get_required_input( 'directory' );

if ( '' === $directory ) {
    echo format_error( escape_sequence( '90' ) . 'Directory empty' );
}

$path = getcwd() . '/' . $directory;

echo $api_token, PHP_EOL;
echo $zone_id, PHP_EOL;

$command = "rm -rf $path";

echo run_command( $command );

$command = "mkdir -p $path";

echo run_command( $command );

/**
 * Test token.
 *
 * @link https://developers.cloudflare.com/api/resources/user/subresources/tokens/methods/verify/
 */
$command = <<<EOT
curl -X GET "https://api.cloudflare.com/client/v4/user/tokens/verify" \
     -H "Authorization: Bearer $api_token" \
     -H "Content-Type:application/json"
EOT;

// echo run_command( $command );

/**
 * Zone details.
 *
 * @link https://developers.cloudflare.com/api/resources/zones/methods/get/
 */
$zone_details_json = cloudflare_api( "https://api.cloudflare.com/client/v4/zones/$zone_id", $api_token );

$zone_details_object = json_decode( $zone_details_json );

$zone_name = $zone_details_object->result->name;

$zone_details_filename = $path . "/{$zone_name}-details.json";

$zone_details_object->result->development_mode = null;

file_put_contents(
	$zone_details_filename,
	json_encode( $zone_details_object, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
);

/**
 * Zone settings.
 *
 * @link https://developers.cloudflare.com/api/resources/zones/subresources/settings/methods/get/
 */
$settings_ids = [
	'cache_level',
	'development_mode',
	'security_level',
	'ssl',
	'webp',
];

foreach ( $settings_ids as $setting_id ) {
	$zone_setting_json = cloudflare_api( "https://api.cloudflare.com/client/v4/zones/$zone_id/settings/$setting_id", $api_token );

	$zone_setting_object = json_decode( $zone_setting_json );

	$zone_setting_filename = $path . "/{$zone_name}-setting-{$setting_id}.json";

	file_put_contents(
		$zone_setting_filename,
		json_encode( $zone_setting_object, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
	);
}

/**
 * Google Tag Gateway settings.
 *
 * @link https://api.cloudflare.com/client/v4/zones/cccf7e3fbf031db08a0d482bc8a25e55/settings/google-tag-gateway/config
 */
$zone_setting_google_tag_gateway_json = cloudflare_api( "https://api.cloudflare.com/client/v4/zones/$zone_id/settings/google-tag-gateway/config", $api_token );

$zone_setting_google_tag_gateway_object = json_decode( $zone_setting_google_tag_gateway_json );

$zone_setting_google_tag_gateway_filename = $path . "/{$zone_name}-setting-google-tag-gateway-config.json";

file_put_contents(
	$zone_setting_google_tag_gateway_filename,
	json_encode( $zone_setting_google_tag_gateway_object, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
);

/**
 * DNS-records.
 *
 * @ilnk https://developers.cloudflare.com/api/resources/dns/subresources/records/methods/export/
 */
$zone_dns_records = cloudflare_api( "https://api.cloudflare.com/client/v4/zones/$zone_id/dns_records/export", $api_token );

/**
 * Redact exported date.
 */
$zone_dns_records = preg_replace(
	'/^;; Exported:   \d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/m',
	';; Exported:   ●●●●-●●-●● ●●:●●:●●',
	$zone_dns_records
);

/**
 * Redact SOA record serial number.
 *
 * Example:
 * example.com	3600	IN	SOA	harmony.ns.cloudflare.com. dns.cloudflare.com. 2049785926 10000 2400 604800 3600
 */
$zone_dns_records = preg_replace(
	'/^(\S+\s+\d+\s+IN\s+SOA\s+\S+\s+\S+\s+)(\d{10})(\s+\d+\s+\d+\s+\d+\s+\d+)$/m',
	'$1●●●●●●●●●●$3',
	$zone_dns_records
);

$zone_dns_records_filename = $path . "/{$zone_name}.zone";

file_put_contents(
	$zone_dns_records_filename,
	$zone_dns_records
);

/**
 * Rulesets.
 *
 * @link https://developers.cloudflare.com/api/resources/rulesets/methods/list/
 * @link https://developers.cloudflare.com/api/resources/rate_limits/methods/list/
 */
$zone_rulesets_json = cloudflare_api( "https://api.cloudflare.com/client/v4/zones/$zone_id/rulesets", $api_token );

$zone_rulesets_object = json_decode( $zone_rulesets_json );

foreach ( $zone_rulesets_object->result as $i => $item ) {
	if ( 'managed' === $item->kind ) {
		$zone_rulesets_object->result[ $i ]->last_updated = null;
		$zone_rulesets_object->result[ $i ]->version      = null;
	}
}

usort(
	$zone_rulesets_object->result,
	function( $a, $b ) {
		return strcmp( $a->id, $b->id );
	}
);

$zone_rulesets_filename = $path . "/{$zone_name}-rulesets.json";

file_put_contents(
	$zone_rulesets_filename,
	json_encode( $zone_rulesets_object, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
);

/**
 * Ruleset details.
 *
 * @link https://developers.cloudflare.com/api/resources/rulesets/methods/get/
 */
$items = $zone_rulesets_object->result;

$items = array_filter(
    $items,
    function ( $item ) {
        if ( 'managed' === $item->kind ) {
            return false;
        }

        return true;
    }
);

foreach ( $items as $item ) {
	$ruleset_id = $item->id;

	$zone_ruleset_json = cloudflare_api( "https://api.cloudflare.com/client/v4/zones/$zone_id/rulesets/$ruleset_id", $api_token );

	$zone_ruleset_object = json_decode( $zone_ruleset_json );

	$zone_ruleset_filename = $path . "/{$zone_name}-ruleset-{$ruleset_id}.json";

	file_put_contents(
		$zone_ruleset_filename,
		json_encode( $zone_ruleset_object, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
	);
}

/**
 * Check Git status.
 */
$status = trim( shell_exec( 'git status --porcelain' ) );

if ( '' === $status ) {
	echo 'No changes';

	exit( 0 );
}

/**
 * GitHub CLI.
 *
 * @link https://cli.github.com/
 */
run_command( 'gh auth status' );

/**
 * Git.
 */
$date = date( 'Y-m-d' );

$branch = "pronamic-cloudflare-config-exporter/$date";

$pr_title = "Cloudflare Config Update ($date)";

$pr_body = "Automated backup of Cloudflare settings on $date.";

run_command( 'git config user.name "pronamic-cloudflare-config-exporter[bot]"' );
run_command( 'git config user.email "pronamic-cloudflare-config-exporter[bot]@users.noreply.github.com"' );

run_command( "git checkout -b $branch" );

run_command( 'git add .' );

run_command( "git commit -m '$pr_title'" );

run_command( "git push origin $branch" );

/**
 * GitHub PR create.
 *
 * @link https://cli.github.com/manual/gh_pr_create
 */
$command = <<<EOT
gh pr create \
	--title "$pr_title" \
	--body "$pr_body"
EOT;

run_command( $command );

run_command( 'gh pr merge --admin --merge --delete-branch' );
