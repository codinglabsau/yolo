import os
import re
import json
import boto3
import subprocess
import urllib.request
from pathlib import Path


def handler(event, context):
    detail = event.get('detail', {})

    if detail.get('event_name') != 'Recording End':
        return

    stage_arn = (event.get('resources') or [None])[0]
    src_bucket = detail.get('recording_s3_bucket_name')
    prefix = detail.get('recording_s3_key_prefix')

    if not stage_arn or not src_bucket or not prefix:
        return

    ivs = boto3.client('ivs-realtime', region_name=os.environ['IVS_REGION'])
    stage_name = ivs.get_stage(arn=stage_arn)['stage']['name']
    m = re.match(r'^(.+)-live-event-(\d+)$', stage_name)
    if not m:
        return

    tenant_id, live_event_id = m.group(1), int(m.group(2))

    s3 = boto3.client('s3')
    hls_prefix = f'{prefix}/media/hls/'
    local_hls = f'/tmp/{live_event_id}/media/hls'
    Path(local_hls).mkdir(parents=True, exist_ok=True)

    paginator = s3.get_paginator('list_objects_v2')
    for page in paginator.paginate(Bucket=src_bucket, Prefix=hls_prefix):
        for obj in page.get('Contents', []):
            key = obj['Key']
            relative = key[len(hls_prefix):]
            local_path = Path(local_hls) / relative
            local_path.parent.mkdir(parents=True, exist_ok=True)
            s3.download_file(src_bucket, key, str(local_path))

    output_path = f'/tmp/recording_{live_event_id}.mp4'
    subprocess.run(
        ['/opt/bin/ffmpeg', '-i', f'{local_hls}/multivariant.m3u8', '-c:v', 'copy', '-c:a', 'aac', '-movflags', '+faststart', output_path],
        check=True,
        capture_output=True,
    )

    dest_bucket = os.environ['MAIN_S3_BUCKET']
    dest_key = f'tmp/realtime-mp4/{live_event_id}/recording.mp4'
    s3.upload_file(output_path, dest_bucket, dest_key)

    payload = json.dumps({
        'tenant_id': tenant_id,
        'live_event_id': live_event_id,
        'mp4_s3_url': f's3://{dest_bucket}/{dest_key}',
    }).encode()

    req = urllib.request.Request(
        os.environ['WEBHOOK_URL'],
        data=payload,
        headers={
            'Content-Type': 'application/json',
            'X-Webhook-Secret': os.environ['WEBHOOK_SECRET'],
        },
        method='POST',
    )
    urllib.request.urlopen(req)
