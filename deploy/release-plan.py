#!/usr/bin/env python3
"""Conservative release selection. No server access and no state-changing API calls."""
import json
import os
import re
import subprocess
import time
import urllib.request
from pathlib import Path


def git(*args):
    return subprocess.check_output(['git', *args], stderr=subprocess.DEVNULL).decode().strip()


def api(path):
    request = urllib.request.Request('https://api.github.com/repos/' + os.environ['GITHUB_REPOSITORY'] + '/' + path,
        headers={'Authorization': 'Bearer ' + os.environ['GH_TOKEN'], 'Accept': 'application/vnd.github+json', 'User-Agent': 'ablaki-release-plan'})
    with urllib.request.urlopen(request, timeout=5) as response:
        return json.load(response)


def published_base(target):
    # Environment deployment records also include read-only audits. Only a successful
    # publish job certifies a release; failed tests/checks-only jobs never advance it.
    deadline = time.monotonic() + 20
    runs = api('actions/workflows/backend.yml/runs?per_page=50')['workflow_runs']
    for run in runs:
        if time.monotonic() >= deadline:
            return ''  # A slow/unavailable API must not delay selection indefinitely.
        if str(run['id']) == os.environ.get('GITHUB_RUN_ID'):
            continue
        if target == 'production' and run.get('head_branch') != 'master':
            continue
        jobs = api(f"actions/runs/{run['id']}/jobs?per_page=100")['jobs']
        if any(job['name'] == f'Deploy backend to {target}' and job['conclusion'] == 'success' for job in jobs):
            return run['head_sha']
    return ''


def classify(paths, known=True, force=False):
    plan = dict(checks=False, publish=False, runtime=False, database=False, portable=False, additive=False)
    if force or not known:
        return dict.fromkeys(plan, True)
    for path in paths:
        if path.startswith(('docs/', 'agreements/')) or path in ('AGENTS.md', 'README.md', '.gitignore'):
            continue
        plan['checks'] = True
        if path.startswith('yii2/tests/'):
            plan['database'] = plan['portable'] = True
            continue
        plan['publish'] = True
        if path.startswith('docker/nginx/'):
            plan['runtime'] = True  # Includes real CORS/preflight probes.
            continue
        if path.startswith('yii2/') and path not in ('yii2/composer.json', 'yii2/composer.lock'):
            plan['database'] = True
            if any(part in path for part in ('/migrations/', '/world-migrations/', '/config/', '/models/', '/services/', '/support/', '/commands/', '/controllers/')) or path == 'yii2/yii':
                plan['portable'] = True
            if '/migrations/' in path or path.endswith(('CraftSeed.php', 'CraftSetupController.php', 'ActivitySetupController.php', 'ForumSetupController.php')):
                plan['additive'] = True
            continue
        # Runtime, dependencies, deployment automation and unknown files require
        # the full matrix. New categories cannot silently bypass release checks.
        plan.update(checks=True, runtime=True, database=True, portable=True)
    return plan


def changed_files(base, head):
    if not re.fullmatch('[0-9a-f]{40}', base or ''):
        return None
    try:
        git('merge-base', '--is-ancestor', base, head)
        # Include BOTH sides of renames: a runtime file renamed to docs is a deletion.
        output = subprocess.check_output(['git', 'diff', '--no-renames', '--name-only', '-z', base, head])
        return [name.decode('utf-8') for name in output.split(b'\0') if name]
    except (subprocess.CalledProcessError, UnicodeError):
        return None


def main():
    event = os.environ.get('GITHUB_EVENT_NAME')
    forced = os.environ.get('FORCE_FULL') == 'true' or event in ('schedule', 'pull_request')
    target = os.environ.get('TARGET', 'production')
    if target not in ('production', 'test'):
        raise ValueError('Invalid target')
    head = git('rev-parse', 'HEAD')
    base = ''
    if not forced:
        try:
            base = published_base(target)
        except (OSError, ValueError, KeyError):
            print('Published base unavailable; all checks are required.')
    paths = changed_files(base, head)
    plan = classify(paths or [], paths is not None, forced)
    # Explicit activation/test preparation is never reduced to a documentation run.
    if os.environ.get('MAINTENANCE') == 'true':
        plan = classify([], force=True)
    if event in ('schedule', 'pull_request') or os.environ.get('CHECKS_ONLY') == 'true':
        plan['publish'] = False
    values = {key: str(value).lower() for key, value in plan.items()}
    values['base'] = base if paths is not None else ''
    with Path(os.environ['GITHUB_OUTPUT']).open('a', encoding='utf-8') as output:
        output.write(''.join(f'{key}={value}\n' for key, value in values.items()))
    print(json.dumps({'head': head, 'base': values['base'] or 'unknown', 'changed_files': len(paths) if paths is not None else None, **plan}))


if __name__ == '__main__':
    main()
