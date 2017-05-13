#!/usr/bin/python
from __future__ import print_function
from threading import Thread

from flup.server.fcgi import WSGIServer
from flask import Flask, redirect, render_template
from flask_sqlalchemy import SQLAlchemy
from sqlalchemy.engine.url import URL

def get_mysql_url():
    from ConfigParser import RawConfigParser
    sql_ini_fileparser = RawConfigParser()
    sql_ini_fileparser.read('../.sql/my.cnf')
    user = sql_ini_fileparser.get('client', 'user')
    password = sql_ini_fileparser.get('client', 'password')
    return 'mysql://{}:{}@sql.mit.edu/superlatives'.format(user, password)

app = Flask(__name__)
app.debug = True
app.config['SQLALCHEMY_DATABASE_URI'] = get_mysql_url()
app.config['SQLALCHEMY_TRACK_MODIFICATIONS'] = False

db = SQLAlchemy(app)

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
def superlatives():
    return '[{"id": 0, "superlative": "Most Hacky", "slots": 1}, {"id": 1, "superlative": "Most Likely to end up Starving in a Lifeboat", "slots": 4}]'

@app.route('/api/superlative', methods=['POST'])
def superlative():
    pass

# ORM
from sqlalchemy import Column, Integer, String, ForeignKey
from sqlalchemy.ext.declarative import declarative_base

Base = declarative_base()

class Person(Base):
    __tablename__ = 'people'
    id = Column(Integer, primary_key=True)
    name = Column(String)
    kerberos = Column(String)

class Superlative(Base):
    __tablename__ = 'superlatives'
    id = Column(Integer, primary_key=True)
    name = Column(String)
    slots = Column(Integer)

class Vote(Base):
    __tablename__ = 'votes'
    id = Column(Integer, primary_key=True)
    superlative = Column(Integer, ForeignKey('superlatives.id'))

class Entry(Base):
    __tablename__ = 'entries'
    id = Column(Integer, primary_key=True)
    vote = Column(Integer, ForeignKey('votes.id'))
    person = Column(Integer, ForeignKey('people.id'))
    slot = Column(Integer)

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

