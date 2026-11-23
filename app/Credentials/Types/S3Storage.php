<?php

namespace App\Credentials\Types;

use App\Credentials\CredentialField;
use App\Credentials\CredentialType;

/**
 * A bucket on Amazon S3 or an S3-compatible service (MinIO, Cloudflare R2,
 * Backblaze B2, Wasabi and the like), for Upload to S3 nodes.
 */
class S3Storage extends CredentialType
{
    public function key(): string
    {
        return 's3';
    }

    public function label(): string
    {
        return __('credentials.s3.s3_compatible_storage');
    }

    public function description(): string
    {
        return __('credentials.s3.a_bucket_on_amazon_s3');
    }

    public function fields(): array
    {
        return [
            CredentialField::text('access_key_id', __('credentials.s3.access_key_id')),
            CredentialField::secret('secret_access_key', __('credentials.s3.secret_access_key')),
            CredentialField::text('region', __('credentials.s3.region'), default: 'us-east-1', help: __('credentials.s3.region_help')),
            CredentialField::text('bucket', __('credentials.s3.bucket')),
            CredentialField::url('endpoint', __('credentials.s3.endpoint'), help: __('credentials.s3.endpoint_help')),
            CredentialField::select('path_style', __('credentials.s3.address_style'), [
                'no' => __('credentials.s3.virtual_hosted'),
                'yes' => __('credentials.s3.path_style'),
            ], default: 'no'),
        ];
    }
}
