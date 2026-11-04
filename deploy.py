# -*- coding: utf-8 -*-
"""
Jambo API — Production Deployment Runner
Executes remote zero-downtime deployment protocol via SSH.
Supports native OpenSSH client or Paramiko fallback.
Usage: python3 deploy.py [vX.Y.Z]
"""
import os
import sys
import io
import subprocess

sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding='utf-8', errors='replace')
sys.stderr = io.TextIOWrapper(sys.stderr.buffer, encoding='utf-8', errors='replace')

try:
    from paramiko import SSHClient, AutoAddPolicy
    HAS_PARAMIKO = True
except ImportError:
    HAS_PARAMIKO = False

HOST = os.getenv('JAMBO_HOST', 'folina.o2switch.net')
USER = os.getenv('JAMBO_USER', 'gupi7723')
PASS = os.getenv('JAMBO_PASS')
KEY_FILE = os.getenv('JAMBO_SSH_KEY')
REMOTE = os.getenv('JAMBO_REMOTE_DIR', '/home/gupi7723/api.jambostack.site')

def run_command_subprocess(cmd):
    print(f"\n>> Executing: {cmd}")
    ssh_cmd = [
        "ssh",
        "-o", "BatchMode=yes",
        "-o", "ConnectTimeout=15",
    ]
    if KEY_FILE and os.path.exists(KEY_FILE):
        ssh_cmd.extend(["-i", KEY_FILE])
    ssh_cmd.append(f"{USER}@{HOST}")
    ssh_cmd.append(f"cd {REMOTE} && {cmd}")

    proc = subprocess.Popen(ssh_cmd, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, encoding='utf-8', errors='replace')
    for line in proc.stdout:
        print(f"  {line.rstrip()}")
    proc.wait()
    if proc.returncode != 0:
        print(f"  FAILED with exit code {proc.returncode}")
    return proc.returncode

def run_command_paramiko(ssh, cmd, timeout=300):
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

    print(f"> Deploying to {USER}@{HOST}:{REMOTE}...")

    ssh = None
    if HAS_PARAMIKO and PASS:
        try:
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
                connect_kwargs['allow_agent'] = True
                connect_kwargs['look_for_keys'] = True
            ssh.connect(**connect_kwargs)
        except Exception as e:
            print(f"Paramiko connection failed ({e}), falling back to OpenSSH subprocess...")
            ssh = None

    def execute_step(cmd):
        if ssh is not None:
            return run_command_paramiko(ssh, cmd)
        return run_command_subprocess(cmd)

    steps = [
        ("Git sync", f"git fetch --all --tags --prune && git checkout {target_tag}" if target_tag else "git pull origin main"),
        ("Composer dependencies", "composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-scripts"),
        ("Frontend build", "if command -v npm >/dev/null 2>&1; then npm ci --prefer-offline && npm run build; else echo 'Notice: npm not found in server environment, skipping frontend build.'; fi"),
        ("Doctrine migrations", "php bin/console doctrine:migrations:migrate --no-interaction --all-or-nothing"),
        ("Cache warmup", "php bin/console cache:clear --no-warmup && php bin/console cache:warmup --env=prod"),
    ]

    for label, cmd in steps:
        print(f"\n--- [Step: {label}] ---")
        rc = execute_step(cmd)
        if rc != 0:
            print(f"\nABORTING: Step '{label}' failed.")
            if ssh:
                ssh.close()
            sys.exit(1)

    print("\n========================================================")
    print(" DEPLOYMENT SUCCEEDED!")
    print(" Live URL: https://api.jambostack.site")
    print("========================================================")
    if ssh:
        ssh.close()

if __name__ == '__main__':
    main()
