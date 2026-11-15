<?php
return ['app_id'=>env('MOSH_FACEBOOK_APP_ID',''),'app_secret'=>env('MOSH_FACEBOOK_APP_SECRET',''),
    'redirect_uri'=>env('MOSH_FACEBOOK_REDIRECT_URI',''),'public_base_url'=>env('MOSH_FACEBOOK_PUBLIC_BASE_URL',''),
    'graph_version'=>env('MOSH_FACEBOOK_GRAPH_VERSION','v26.0')];
