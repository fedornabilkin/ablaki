#!/usr/bin/env python3
import importlib.util
import subprocess
import tempfile
import unittest
import sys
from pathlib import Path
from unittest.mock import patch

sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location('release_plan', Path(__file__).parents[1] / 'release-plan.py')
plan = importlib.util.module_from_spec(spec)
spec.loader.exec_module(plan)


class ReleasePlanTests(unittest.TestCase):
    def test_docs_and_tests_do_not_publish(self):
        self.assertFalse(any(plan.classify(['docs/plan/a.md', 'AGENTS.md']).values()))
        result = plan.classify(['yii2/tests/release/world-rating.php'])
        self.assertFalse(result['publish'])
        self.assertTrue(result['database'] and result['portable'])

    def test_proxy_runs_cors_without_database(self):
        result = plan.classify(['docker/nginx/add/php.conf'])
        self.assertTrue(result['runtime'] and result['publish'])
        self.assertFalse(result['database'] or result['portable'])

    def test_gameplay_and_migrations_are_checked(self):
        for path in ['yii2/common/modules/world/models/domain/GardenHarvest.php', 'yii2/common/services/user/Wallet.php']:
            result = plan.classify([path])
            self.assertTrue(result['database'] and result['portable'])
            self.assertFalse(result['runtime'])
        self.assertTrue(plan.classify(['yii2/console/migrations/new.php'])['additive'])

    def test_unknown_files_and_missing_base_fail_closed(self):
        for path in ['docker/php/Dockerfile', 'yii2/composer.lock', '.github/workflows/backend.yml', 'new-service/code.py']:
            result = plan.classify([path])
            self.assertTrue(result['runtime'] and result['database'] and result['portable'])
        self.assertTrue(all(plan.classify([], known=False).values()))
        self.assertTrue(all(plan.classify(['docs/a.md'], force=True).values()))

    def test_base_is_successful_publish_not_audit_or_failed_release(self):
        runs = {'workflow_runs': [{'id': 3, 'head_branch': 'master', 'head_sha': 'c'*40}, {'id': 2, 'head_branch': 'master', 'head_sha': 'b'*40}, {'id': 1, 'head_branch': 'master', 'head_sha': 'a'*40}]}
        responses = [runs, {'jobs': [{'name': 'Read-only production preflight', 'conclusion': 'success'}]}, {'jobs': [{'name': 'Deploy backend to production', 'conclusion': 'failure'}]}, {'jobs': [{'name': 'Deploy backend to production', 'conclusion': 'success'}]}]
        with patch.object(plan, 'api', side_effect=responses), patch.dict(plan.os.environ, {'GITHUB_RUN_ID': '9'}):
            self.assertEqual(plan.published_base('production'), 'a'*40)

    def test_diff_includes_intermediate_commits_and_rename_source(self):
        with tempfile.TemporaryDirectory() as directory:
            def git(*args):
                return subprocess.check_output(['git', '-C', directory, *args], stderr=subprocess.DEVNULL).decode().strip()
            git('init'); git('config', 'user.name', 'Fixture'); git('config', 'user.email', 'fixture@example.invalid')
            root = Path(directory); (root/'source.php').write_text('one'); git('add', '.'); git('commit', '-qm', 'published'); base = git('rev-parse', 'HEAD')
            (root/'source.php').write_text('two'); git('add', '.'); git('commit', '-qm', 'failed release')
            (root/'docs').mkdir(); git('mv', 'source.php', 'docs/archive.md'); git('commit', '-qm', 'documentation')
            previous = Path.cwd()
            try:
                plan.os.chdir(directory)
                self.assertEqual(set(plan.changed_files(base, 'HEAD')), {'source.php', 'docs/archive.md'})
                self.assertIsNone(plan.changed_files('f'*40, 'HEAD'))
            finally:
                plan.os.chdir(previous)

    def test_read_only_runs_and_explicit_maintenance(self):
        for event, checks_only, maintenance, expected_publish in [
            ('schedule', '', '', False), ('pull_request', '', '', False),
            ('workflow_dispatch', 'true', 'true', False),
            ('workflow_dispatch', '', 'true', True), ('push', '', '', False),
        ]:
            with self.subTest(event=event, maintenance=maintenance), tempfile.TemporaryDirectory() as directory:
                output = Path(directory) / 'output'
                with patch.dict(plan.os.environ, {'GITHUB_EVENT_NAME': event, 'CHECKS_ONLY': checks_only,
                    'MAINTENANCE': maintenance, 'FORCE_FULL': '', 'TARGET': 'production', 'GITHUB_OUTPUT': str(output)}), \
                    patch.object(plan, 'git', return_value='a'*40), patch.object(plan, 'published_base', return_value='a'*40), \
                    patch.object(plan, 'changed_files', return_value=['docs/a.md']):
                    plan.main()
                values = dict(line.split('=', 1) for line in output.read_text().splitlines())
                self.assertEqual(values['publish'], str(expected_publish).lower())
                if event in ('schedule', 'pull_request') or maintenance:
                    self.assertEqual(values['database'], 'true')


if __name__ == '__main__':
    unittest.main()
