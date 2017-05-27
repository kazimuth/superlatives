#!/usr/bin/python

'''Superlatives webapp for putz.'''

# pylint: disable = missing-docstring,invalid-name,redefined-outer-name
# pylint: disable = no-member,too-few-public-methods,redefined-builtin
# pylint: disable = broad-except,line-too-long

from __future__ import print_function
from threading import Thread
import json
import base64
import requests
import uuid

from requests.auth import HTTPBasicAuth
from flup.server.fcgi import WSGIServer
from flask import Flask, session, redirect, render_template, request
from flask_sqlalchemy import SQLAlchemy
from oic.oic import Client
from oic.utils.authn.client import CLIENT_AUTHN_METHOD

def log(fmt, *args):
    from sys import stderr
    print(fmt.format(*args), file=stderr)

with open('secrets/secrets.json') as sf:
    data = json.load(sf)
    SQL_USER = data['sql_user']
    SQL_PASS = data['sql_pass']
    FLASK_SECRET_KEY = data['secret_key']
    DOMAIN = data['domain']
with open('secrets/client_secrets.json') as sf:
    data = json.load(sf)
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

def rndstr():
    return uuid.uuid4().hex

# gross oidc stuff
@app.route('/login')
def login_page():
    # Check if already logged in
    if 'email' in session:
        return redirect('/superlatives')

    client = Client(client_authn_method=CLIENT_AUTHN_METHOD)
    error = None

    if "code" in request.args and "state" in request.args and request.args["state"] == session["state"]:
        log('{} {} {} {}', 'https://oidc.mit.edu/token', CLIENT_ID, CLIENT_SECRET, {"grant_type": "authorization_code", "code": request.args["code"], "redirect_uri": DOMAIN+'/login'})
        r = requests.post('https://oidc.mit.edu/token', auth=HTTPBasicAuth(CLIENT_ID, CLIENT_SECRET),
                          data={"grant_type": "authorization_code",
                                "code": request.args["code"],
                                "redirect_uri": DOMAIN+'/login'})
        log('request: code {}, text {}', request.args["code"], r.text)
        auth_token = json.loads(r.text)["access_token"]
        r = requests.get('https://oidc.mit.edu/userinfo', headers={"Authorization": "Bearer " + auth_token})
        user_info = json.loads(r.text)

        if "email" in user_info and user_info["email_verified"] is True and user_info["email"].endswith("@mit.edu"):
            # Authenticated
            email = user_info["email"]

            user = User.query.filter_by(email=email).first()
            if user is None:
                user = User(email=email)
                db.session.add(user)
                db.session.commit()

            session['email'] = email
            return redirect('/superlatives')
        else:
            if not "email" in user_info:
                error = "We need your email to work."
            else:
                error = "Invalid Login."

    session["state"] = rndstr()
    session["nonce"] = rndstr()

    args = {
        "client_id": CLIENT_ID,
        "response_type": ["code"],
        "scope": ["email", "openid", "profile"],
        "state": session["state"],
        "nonce": session["nonce"],
        "redirect_uri": DOMAIN+'/login'
    }

    log('set state: {}', session["state"])

    auth_req = client.construct_AuthorizationRequest(request_args=args)
    login_url = auth_req.request('https://oidc.mit.edu/authorize')

    return render_template('login.html', login_url=login_url, error=error)

def auth(route):
    def route_d(*args, **kwargs):
        if 'email' in session:
            return route(*args, **kwargs)
        return redirect('/login')

    route_d.func_name = route.func_name
    return route_d

@app.route('/superlatives')
@auth
def main_page():
    return render_template('superlatives.html', people=Person.query.all())

@app.route('/')
def index():
    return redirect('/login', code=302)

@app.route('/api/people')
@auth
def people():
    return json.dumps(Person.query.all())

@app.route('/api/person', methods=['POST'])
@auth
def person():
    data = json.loads(request.formdata)
    name = data['name']
    kerberos = data['kerberos']
    p = Person(name, kerberos)
    db.session.add(p)
    db.session.commit()
    return '', 200

@app.route('/api/superlatives')
@auth
def superlatives():
    return json.dumps(Superlative.query.all())

@app.route('/api/superlative', methods=['POST'])
@auth
def superlative():
    data = json.loads(request.formdata)
    text = data['text']
    slots = int(data['slots'])
    superlative = Superlative(text, slots)
    db.session.add(superlative)
    db.session.commit()
    return '', 200

@app.route('/api/vote', methods=['POST'])
@auth
def vote():
    data = json.loads(request.formdata)
    superlative = int(data['superlative'])
    people = data['people']

# ORM
class User(db.Model):
    __tablename__ = 'user'
    id = db.Column(db.Integer, primary_key=True)
    email = db.Column(db.String(128), nullable=False)

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
    log('start: {}', start)
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

