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
from flask import Flask, session, redirect, render_template, request, jsonify
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
# note: we're a SPA, except for login, which is, uh, odd
# hope you're up for using multiple templating apis!
# (login is rendered with flask, superlatives is rendered with Mithril.js on
# the client side)
@app.route('/login')
def login_page():
    # Check if already logged in
    if 'email' in session:
        return redirect('/superlatives')

    client = Client(client_authn_method=CLIENT_AUTHN_METHOD)
    error = None

    try:
        if "code" in request.args and "state" in request.args and request.args["state"] == session["state"]:
            r = requests.post('https://oidc.mit.edu/token', auth=HTTPBasicAuth(CLIENT_ID, CLIENT_SECRET),
                              data={"grant_type": "authorization_code",
                                    "code": request.args["code"],
                                    "redirect_uri": DOMAIN+'/login'})
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
    except Exception as e:
        error = str(e)

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

def get_user():
    '''Can only be called from auth routes.'''
    return User.query.filter_by(email=session['email']).first()

@app.route('/superlatives')
@auth
def main_page():
    return render_template('superlatives.html')

@app.route('/')
def index():
    return redirect('/login', code=302)

@app.route('/api/people')
@auth
def people():
    return jsonify([p.serialize() for p in Person.query.all()])

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
    superlatives = Superlative.query.all()
    votes = Vote.query.filter_by(user=get_user().id)
    votes = dict(((vote.superlative, vote) for vote in votes))

    results = []
    for sup in superlatives:
        ser = sup.serialize()
        if sup.id in votes:
            ser['people'] = [entry.person for entry in votes[sup.id].entries]
        results.append(ser)

    return jsonify(results)

@app.route('/api/superlative', methods=['POST'])
@auth
def superlative():
    data = json.loads(request.formdata)
    text = data['text']
    slots = int(data['slots'])
    superlative = Superlative(text.lower(), slots)
    db.session.add(superlative)
    db.session.commit()
    return '', 200

@app.route('/api/vote', methods=['POST'])
@auth
def vote():
    data = json.loads(request.formdata)
    superlative = Superlative.query.get(int(data['superlative']))
    user = get_user()

    people = sorted(set(data['people']))

    if len(people) != superlative.slots:
        return jsonify({
            'error': 'mismatched people count',
            'expected': superlative.slots,
            'got': len(people)
        }), 400

    vote = Vote.query.filter_by(superlative=superlative, user=user)
    if vote:
        for i in xrange(len(vote.entries)):
            vote.entries[i].person = people[i]
    else:
        # let's play spot the race condition
        vote = Vote(superlative, user)
        db.session.add(vote)
        db.session.commit()
        for person in people:
            entry = Entry(vote, person['id'])
            db.session.add(person)
    db.session.commit()
    
# ORM
class User(db.Model):
    __tablename__ = 'user'
    id = db.Column(db.Integer, primary_key=True)
    email = db.Column(db.String(128), nullable=False, unique=True)
    votes = db.relationship('Vote')

    def __init__(self, email):
        self.email = email

class Person(db.Model):
    __tablename__ = 'people'
    id = db.Column(db.Integer, primary_key=True)
    name = db.Column(db.String(256), unique=True, nullable=False)
    kerberos = db.Column(db.String(128), unique=True, nullable=False)

    def __init__(self, name, kerberos):
        self.name = name
        self.kerberos = kerberos

    def serialize(self):
        return {'id': self.id, 'name':self.name, 'kerberos':self.kerberos}

class Superlative(db.Model):
    __tablename__ = 'superlatives'
    id = db.Column(db.Integer, primary_key=True)
    text = db.Column(db.String(256), nullable=False, unique=True)
    slots = db.Column(db.Integer, nullable=False)

    def __init__(self, text, slots):
        self.text = text
        self.slots = slots

    def serialize(self):
        return {'id': self.id, 'text': self.text, 'slots': self.slots}

class Vote(db.Model):
    __tablename__ = 'votes'
    id = db.Column(db.Integer, primary_key=True)
    superlative = db.Column(db.Integer, db.ForeignKey('superlatives.id'), nullable=False)
    user = db.Column(db.Integer, db.ForeignKey('user.id'), nullable=False)
    entries = db.relationship('Entry', lazy='joined')

    def __init__(self, superlative, user):
        self.superlative = superlative
        self.user = user

class Entry(db.Model):
    __tablename__ = 'entries'
    id = db.Column(db.Integer, primary_key=True)
    vote = db.Column(db.Integer, db.ForeignKey('votes.id'), nullable=False)
    person = db.Column(db.Integer, db.ForeignKey('people.id'), nullable=False)

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

