# -*- coding: utf-8 -*-
"""
Jambo API — Production Deployment Runner
Executes remote zero-downtime deployment protocol via SSH.
Supports SSH key authentication (recommended) or password via environment.
Usage: python deploy.py [vX.Y.Z]
"""
import os
import sys
import io

sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8', errors='replace')
sys.stderr = io.TextIOWrapper(sys.stderr.buffer, encoding='utf-8', errors='replace')

from paramiko import SSHClient, AutoAddPolicy

HOST = os.getenv('JAMBO_HOST', 'api.jambostack.site')
USER = os.getenv('JAMBO_USER', 'jprud67')
PASS = os.getenv('JAMBO_PASS')
KEY_FILE = os.getenv('JAMBO_SSH_KEY')
REMOTE = os.getenv('JAMBO_REMOTE_DIR', '/home/jprud67/web/api.jambostack.site/public_html')

def run_command(ssh, cmd, timeout=300):
    print(f"\n>> Executing: {cmd}")
    _, stdout, stderr = ssh.exec_command(f"cd {REMOTE} && {cmd}", timeout=timeout)
    
    for line in iter(stdout.readline, ""):
        print(f"  {line.rstrip()}")
    
    exit_code = stdout.channel.recv_exit_status()
    if exit_code != 0:
        err = stderr.read().decode('utf-8', errors='replace')
        print(f"  FAILED with exit code {exit_code}")
        if err.strip():
            print(f"  STDERR: {err.strip()}")
        return exit_code
    return 0

def main():
    target_tag = sys.argv[1] if len(sys.argv) > 1 else None

    print(f"> Connecting to {USER}@{HOST}...")
    ssh = SSHClient()
    ssh.set_missing_host_key_policy(AutoAddPolicy())

    connect_kwargs = {
        'hostname': HOST,
        'username': USER,
        'timeout': 15,
    }

    if KEY_FILE and os.path.exists(KEY_FILE):
        connect_kwargs['key_filename'] = KEY_FILE
    elif PASS:
        connect_kwargs['password'] = PASS
    else:
        # Fallback to default SSH agent or ~/.ssh/id_rsa
        connect_kwargs['allow_agent'] = True
        connect_kwargs['look_for_keys'] = True

    try:
        ssh.connect(**connect_kwargs)
    except Exception as e:
        print(f"Connection error: {e}")
        sys.exit(1)

    steps = [
        ("Git sync", f"git fetch --all --tags --prune && git checkout {target_tag}" if target_tag else "git pull origin main"),
        ("Composer dependencies", "composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-scripts"),
        ("Frontend build", "npm ci --prefer-offline && npm run build"),
        ("Doctrine migrations", "php bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing"),
        ("Cache warmup", "php bin/console cache:clear --no-warmup && php bin/console cache:warmup --env=prod"),
    ]

    for label, cmd in steps:
        print(f"\n--- [Step: {label}] ---")
        rc = run_command(ssh, cmd)
        if rc != 0:
            print(f"\nABORTING: Step '{label}' failed.")
            ssh.close()
            sys.exit(1)

    print("\n========================================================")
    print(" DEPLOYMENT SUCCEEDED!")
    print(" Live URL: https://api.jambostack.site")
    print("========================================================")
    ssh.close()

if __name__ == '__main__':
    main()
