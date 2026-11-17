#!/opt/homebrew/opt/python@3.14/bin/python3.14
import sys,subprocess
try:
 r=subprocess.run(sys.argv[2:],timeout=float(sys.argv[1]));sys.exit(r.returncode)
except subprocess.TimeoutExpired:
 sys.exit(124)
