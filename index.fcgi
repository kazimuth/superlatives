#!/usr/bin/python

'''Superlatives webapp for putz.'''

# pylint: disable = missing-docstring,invalid-name,redefined-outer-name
# pylint: disable = no-member,too-few-public-methods,redefined-builtin
# pylint: disable = broad-except,line-too-long

from __future__ import print_function
from threading import Thread
import json
import base64

from flup.server.fcgi import WSGIServer
from flask import Flask, session, redirect, render_template
from flask_sqlalchemy import SQLAlchemy
from flask_oidc import OpenIDConnect

# to satisy pylint
request = None

def log(fmt, *args):
    from sys import stderr
    print(fmt.format(*args), file=stderr)

with open('secrets/secrets.json') as sf:
    data = json.load(sf)
    print(data)
    SQL_USER = data['sql_user']
    SQL_PASS = data['sql_pass']
    FLASK_SECRET_KEY = data['secret_key']
with open('secrets/client_secrets.json') as sf:
    data = json.load(sf)
    print(data)
    CLIENT_ID = data['web']['client_id']
    CLIENT_SECRET = data['web']['client_secret']

del data

app = Flask(__name__)
app.debug = True
app.secret_key = FLASK_SECRET_KEY
app.config['SQLALCHEMY_DATABASE_URI'] = \
        'mysql://{}:{}@sql.mit.edu/jhgilles+superlatives' \
        .format(SQL_USER, SQL_PASS)
app.config['SQLALCHEMY_TRACK_MODIFICATIONS'] = False

db = SQLAlchemy(app)

@app.route('/superlatives')
def main_page():
    return render_template('superlatives.html', people=Person.query.all())

@app.route('/')
def index():
    return redirect('/superlatives', code=302)

def rndstr():
    import os
    return base64.b64encode(os.urandom(64))

@app.route('/login')
def login_page():
    pass


@app.route('/api/people')
def people():
    return json.dumps(Person.query.all())

@app.route('/api/person', methods=['POST'])
def person():
    data = json.loads(request.formdata)
    name = data['name']
    kerberos = data['kerberos']
    p = Person(name, kerberos)
    db.session.add(p)
    db.session.commit()
    return '', 200

@app.route('/api/superlatives')
def superlatives():
    return json.dumps(Superlative.query.all())

@app.route('/api/superlative', methods=['POST'])
def superlative():
    data = json.loads(request.formdata)
    text = data['text']
    slots = int(data['slots'])
    superlative = Superlative(text, slots)
    db.session.add(superlative)
    db.session.commit()
    return '', 200

@app.route('/api/vote', methods=['POST'])
def vote():
    data = json.loads(request.formdata)
    superlative = int(data['superlative'])
    people = data['people']

# ORM
class User(db.Model):
    __tablename__ = 'user'
    id = db.Column(db.Integer, primary_key=True)
    kerberos = db.Column(db.String(128), nullable=False)

class Person(db.Model):
    __tablename__ = 'people'
    id = db.Column(db.Integer, primary_key=True)
    name = db.Column(db.String(256), unique=True, nullable=False)
    kerberos = db.Column(db.String(128), unique=True, nullable=False)

    def __init__(self, name, kerberos):
        self.name = name
        self.kerberos = kerberos

class Superlative(db.Model):
    __tablename__ = 'superlatives'
    id = db.Column(db.Integer, primary_key=True)
    text = db.Column(db.String(256), nullable=False)
    slots = db.Column(db.Integer, nullable=False)

    def __init__(self, text, slots):
        self.text = text
        self.slots = slots

class Vote(db.Model):
    __tablename__ = 'votes'
    id = db.Column(db.Integer, primary_key=True)
    superlative = db.Column(db.Integer, db.ForeignKey('superlatives.id'), nullable=False)
    user = db.Column(db.Integer, db.ForeignKey('user.id'), nullable=False)
    entries = db.relationship('Entry', backref='votes')

    def __init__(self, superlative):
        self.superlative = superlative

class Entry(db.Model):
    __tablename__ = 'entries'
    id = db.Column(db.Integer, primary_key=True)
    vote = db.Column(db.Integer, db.ForeignKey('votes.id'))
    person = db.Column(db.Integer, db.ForeignKey('people.id'))

    def __init__(self, vote, person):
        self.vote = vote
        self.person = person

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

if __name__ == '__main__':
    log('starting server...')
    Thread(target=die_on_change).start()
    WSGIServer(app).run()

