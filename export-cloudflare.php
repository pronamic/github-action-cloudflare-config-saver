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
$command = <<<EOT
curl https://api.cloudflare.com/client/v4/zones/$zone_id \
     -H "Authorization: Bearer $api_token" \
     -H "Content-Type:application/json"
EOT;

$zone_details_json = run_shell_exec( $command );

$zone_details_object = json_decode( $zone_details_json );

$zone_name = $zone_details_object->result->name;

$zone_details_filename = $path . "/{$zone_name}-details.json";

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
	$command = <<<EOT
	curl https://api.cloudflare.com/client/v4/zones/$zone_id/settings/$setting_id \
		-H "Authorization: Bearer $api_token" \
		-H "Content-Type:application/json"
	EOT;

	$zone_setting_json = run_shell_exec( $command );
    
	$zone_setting_object = json_decode( $zone_setting_json );
    
	$zone_setting_filename = $path . "/{$zone_name}-setting-{$setting_id}.json";
    
	file_put_contents(
		$zone_setting_filename,
		json_encode( $zone_setting_object, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
	);
}

/**
 * DNS-records.
 * 
 * @ilnk https://developers.cloudflare.com/api/resources/dns/subresources/records/methods/export/
 */
$command = <<<EOT
curl https://api.cloudflare.com/client/v4/zones/$zone_id/dns_records/export \
     -H "Authorization: Bearer $api_token" \
     -H "Content-Type:application/json"
EOT;

$zone_dns_records = run_shell_exec( $command );

$zone_dns_records = preg_replace(
	'/^;; Exported:   \d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/m',
	';; Exported:   ●●●●-●●-●● ●●:●●:●●',
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
$command = <<<EOT
curl https://api.cloudflare.com/client/v4/zones/$zone_id/rulesets \
	-H "Authorization: Bearer $api_token" \
	-H "Content-Type:application/json"
EOT;

$zone_rulesets_json = run_shell_exec( $command );

$zone_rulesets_object = json_decode( $zone_rulesets_json );

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

	$command = <<<EOT
	curl https://api.cloudflare.com/client/v4/zones/$zone_id/rulesets/$ruleset_id \
		-H "Authorization: Bearer $api_token" \
		-H "Content-Type:application/json"
	EOT;

	$zone_ruleset_json = run_shell_exec( $command );

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
 * Git.
 */
$date = date( 'Y-m-d' );

$branch = "cloudflare-config-update-$date";

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
run_command( 'gh auth status' );

$timestamp = date( 'Y-m-d H:i' );

$pr_title = "Cloudflare Config Update – $timestamp";

$command = <<<EOT
gh pr create \
	--title "$pr_title" \
    --body "$pr_body"
EOT;

run_command( $command );

run_command( 'gh pr merge --auto --merge --delete-branch' );
