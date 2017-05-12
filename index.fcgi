#!/usr/bin/python
from __future__ import print_function
from threading import Thread

from flup.server.fcgi import WSGIServer
from flask import Flask, redirect, render_template

app = Flask(__name__)
app.debug = True

@app.route('/superlatives')
def superlatives():
    return render_template('superlatives.html')

@app.route('/')
def index():
    return redirect('/superlatives', code=302)

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


