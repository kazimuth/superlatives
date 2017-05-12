#!/usr/bin/python
from __future__ import print_function
from threading import Thread

from flup.server.fcgi import WSGIServer
from flask import Flask, redirect, render_template
from flask.ext.sqlalchemy import SQLAlchemy

app = Flask(__name__)
app.debug = True

@app.route('/superlatives')
def main_page():
    return render_template('superlatives.html')

@app.route('/')
def index():
    return redirect('/superlatives', code=302)

@app.route('/api/people')
def people():
    return '[{"id": 0, "name": "Ryan Q Putz", "kerberos": "putz"}, {"id": 1, "name": "James Gilles", "kerberos": "jhgilles"}, {"id": 2, "name": "Shreyas Kapur", "kerberos": "shreyask"}]'

@app.route('/api/person', methods=['POST'])
def person():
    pass

@app.route('/api/superlatives')
def list_superlatives():
    return '[{"id": 0, "superlative": "Most Hacky", "slots": 1}, {"id": 1, "superlative": "Most Likely to end up Starving in a Lifeboat", "slots": 4}]'

@app.route('/api/superlative', methods=['POST'])
def person():
    pass

def die_on_change():
    import os.path
    import sys
    import time
    start = os.path.getmtime('./index.fcgi')
    while True:
        time.sleep(1)
        current = os.path.getmtime('./index.fcgi')
        if current > start:
            log('index modified, dying')
            sys.exit(0)

def log(fmt, *args):
    from sys import stderr
    print(fmt.format(*args), file=stderr)

if __name__ == '__main__':
    log('starting server...')
    Thread(target=die_on_change).start()
    WSGIServer(app).run()

