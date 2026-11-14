<?php

namespace Modules\Gateways\CreatorFeed;

use Illuminate\Database\Eloquent\Model;

/** A CreatorFeed webhook event already handled (CreatorFeed-Event-Id). */
class CreatorFeedWebhookEvent extends Model
{
    protected $table = 'creatorfeed_webhook_events';

    protected $guarded = ['id'];
}
