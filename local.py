import imp
main = imp.load_source('app', 'index.fcgi')

if __name__ == '__main__':
	app.run(host='0.0.0.0', port=5000)