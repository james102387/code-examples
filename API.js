import Common from './Common';
import URLFormatter from './Common/URLFormatter';
import EventDelegator from './EventDelegator';
import CommonClass from './Common/CommonClass';
class APICore{
	constructor(url: string, method: String = 'GET', data: Object = {}){
		url = `/api/v1/${url}`;
		this._options = {
			url, method, data
		}
		this._csrfToken = document.querySelectorAll('input[name="_token"]')[0].getAttribute('value');
		this._setRequestHeaders = this.setRequestHeaders;
		this._setStatusFunctions = this.setStatusFunctions;
		this.request;
		this._eventName = '';
		EventDelegator.on('callAPI', this._call);
		return this;
	}

	setStatusFunctions(){
		let that = this;
		if(!('failCallback' in this._options) || typeof this._options.failCallback !== 'function'){
			this._options.failCallback = function(){
				let callback;
				switch(that.request.status){
					case 401:
						callback = function(){
							window.demo = true;
							window.needsToRegisterError('make modifications.');
							that.resetAPI('');
						}
					break;
					case 422:
					case 403:
						callback = function(){
							popUpMsg('Please contact your Committee Chairperson or Administrator to make modifications.');
							that.resetAPI('');
						}
					break;
					case 302:
					case 405:
						callback = function(){
							let url = that.request.responseText;
							let data = that.options.data;
							delete data['_method'];
							that.options = {url: '/api/v1/'+url, data: data};
							that.call(that.async);
						}
					break;
					default:
						callback = function(){
							window.unknownError();
							that.resetAPI('');
						}
					break;
				}
				if(that._eventName != ''){
					EventDelegator.on(`ajaxFail${'.'+that._eventName}`, callback);
					EventDelegator.emit(`ajaxFail${'.'+that._eventName}`, that.request);
				}
			}
		}
		if(!('doneCallback' in this._options) || typeof this._options.doneCallback !== 'function'){
			this._options.doneCallback = function(){
				if(that.request.status !== 200){
					that._options.failCallback();
					return;
				}
				if(that._eventName != ''){
					EventDelegator.emit(`ajaxSuccess${'.' + that._eventName}`, that.request);
				}
				that.resetAPI('');
			}
		}
		this.request.onreadystatechange = function(){};
		this.request.onload = this._options.doneCallback;
		this.request.onerror = this._options.failCallback;
	}

	setRequestHeaders(){
		this.request.setRequestHeader('Accept', '*/*');
		this.request.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
		this.request.setRequestHeader('Accept-Language', 'en-US,en;q=0.8');
		this.request.setRequestHeader('Cache-Control', 'no-cache');
		this.request.setRequestHeader('Pragma', 'no-cache');
		this.request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
		this.request.setRequestHeader('X-CSRF-Token', this._csrfToken);
		this.request.setRequestHeader('Authorization', window.apiToken);
	}

	call(async: Boolean = true){
		this.request = new XMLHttpRequest();
		let that = this;
		if(that._options.method === 'GET'){
			this.request.open(this._options.method.toUpperCase(), `${this._options.url}${this._options.sendData}`, async);
		}else{
			this.request.open(this._options.method.toUpperCase(), this._options.url, async);
		}
		this.request.withCredentials = true;
		this._setStatusFunctions();
		if(this._options.method === 'GET'){
			this._options.sendData = URLFormatter.formatGetObject(this._options.url, this._options.data);
			this._setRequestHeaders();
		}else{
			this._options.sendData = URLFormatter.objectToPostString(this._options.data);
			this._setRequestHeaders();
			this.request.send(this._options.sendData);
		}
		this.resetAPI();
	}

	resetAPI(url: string, method: String = 'GET', data: Object = {}){
		url = `/api/v1/${url}`;
		let tmpOptions = {url, method, data};
		this.options = tmpOptions;
	}

	get options (){
		return this._options;
	}

	set options (options) {
		for(var opt in options){
			if(opt === 'url'){
				this._options[opt] = `/api/v1/${options[opt]}`;
			}else{
				this._options[opt] = options[opt];
			}
		}
	}

	set eventName (eventName){
		this._eventName = eventName;
	}
	get req(){
		return this.request;
	}
}

class APICoreV2 extends CommonClass{
	constructor(url: String, method: String =  'GET', data: Object = {}){
		super();
		url = `/api/v1/${url}`;
		this._options = {
			url, method, data
		}
		this._setRequestHeaders = this.setRequestHeaders;
		this.request;
		this._eventName = '';
		this._retry = 0;
		this._async = true;
		this._handleErrors = true;
		return this;
	}

	setRequestHeaders(){
		this.request.setRequestHeader('Accept', '*/*');
		this.request.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
		this.request.setRequestHeader('Accept-Language', 'en-US,en;q=0.8');
		this.request.setRequestHeader('Cache-Control', 'no-cache');
		this.request.setRequestHeader('Pragma', 'no-cache');
		this.request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
		this.request.setRequestHeader('X-CSRF-Token', this._csrfToken);
		this.request.setRequestHeader('Authorization', window.apiToken);
	}

	call(async: Boolean = true){
		this._async = async;
		let that = this;
		return new Promise(function(resolve, reject){
			that.request = new XMLHttpRequest();
			if(that._options.method === 'GET'){
				that._options.sendData = URLFormatter.formatGetObject(that._options.url, that._options.data);
				that.request.open(that._options.method.toUpperCase(), `${that._options.sendData}`, that._async);
			}else{
				that.request.open(that._options.method.toUpperCase(), that._options.url, that._async);
			}
			that.request.withCredentials = true;
			that.request.onreadystatechange = function(){};
			that.request.onload = function(){
				if(that._retry >= 2){
					reject({message: 'retry', request: that.request});
				}
				switch(that.request.status){
					case 200:
						resolve(JSON.parse(that.request.responseText));
						that.request = null;
						EventDelegator.emit('destroy', 'that');
						break;
					case 302:
					case 405:
						that._retry++;
						let url = that.request.responseText;
						let data = that.options.data;
						delete data['_method'];
						that.options = {url: '/api/v1/'+url, data: data};
						that.call(that._async);
						break;
					case 401:
						if(that.handleErrors)
						{
							that._error.showDemoError();
						}
						reject({message: 'demo', request: that.request});
						that.request = null;
						EventDelegator.emit('destroy', 'that');
						break;
					case 403:
						if(that.handleErrors)
						{
							that._error.privilegeError('make modifications.');
						}
						reject({message: 'privilege', request: that.request});
						that.request = null;
						EventDelegator.emit('destroy', 'that');
						break;
					case 204:
						reject({message: 'no content', request: that.request});
						that.request = null;
						EventDelegator.emit('destroy', 'that');
						break;
					case 404:
						reject({message: 'not found', request: that.request});
						that.request = null;
						EventDelegator.emit('destroy', 'that');
						break;
					case 500:
						if(that.handleErrors)
						{
							that._error.unknownError();
						}
						reject({message: 'unknown', request: that.request});
						that.request = null;
						EventDelegator.emit('destroy', 'that');
					default:
						if(that.handleErrors)
						{
							that._error.unknownError();
						}
						reject({message: 'unknown', request: that.request});
						that.request = null;
						EventDelegator.emit('destroy', 'that');
						break;
				}
			}.bind(that);
			that.request.onerror = function(){
				that._promise.reject({message: 'unknown', request: that.request});
			}.bind(that);
			if(that._options.method === 'GET'){
				that._options.sendData = URLFormatter.formatGetObject(that._options.url, that._options.data);
				that._setRequestHeaders();
				that.request.send();
			}else{
				that._options.sendData = URLFormatter.objectToPostString(that._options.data);
				that._setRequestHeaders();
				that.request.send(that._options.sendData);
			}
		});
	}

	set eventName(eventName){
		this._eventName = eventName;
	}
	set csrfToken(token){
		this._csrfToken = token;
	}
	set handleErrors($yesno){
		this._handleErrors = $yesno;
	}
}

let API = (function(APICore){
	let core = null,
		subscribeToEvents = false,
		resetAfterCall = true,
		multipleCalls = false,
		async = true,
		handleErrors = true,
		csrfToken;

	try{
		csrfToken = document.querySelectorAll('input[name="_token"]')[0].getAttribute('value');
	}catch(e){

	}
	let call = function(api: string, method: string, data: Object = {}, eventName: String = ''){
		if(arguments.length < 2) throw new Error('Cannot make a call without the proper data'); //data is optional
		if(core === null) core = new APICore(api, method, data);
		else core.options = {url: api, method, data};
		core.eventName = eventName;
		core.call(async);
	}
	let setOptions = function(options: object){ //if you need to set deep options such as fail or success callbacks
		if(core === null) core = new APICore('');
		core.options = options;
	}
	let getOptions = function(){
		if(core === null) return false;
		return core.options;
	};
	let resetAPI = function(option: Boolean){
		resetAfterCall = option;
	}
	let setAsync = function(asyncBool: Boolean){
		async = asyncBool;
	}
	let request = function(){
		if(core === null) return false;
		return core.req;
	}
	let setMultipleCalls = function(set : Boolean){
		multipleCalls = set;
	}
	let setHandleErrors = function(set : Boolean){
		handleErrors = set;
	}

	let handle = function(api: string, method: string, data: Object = {}, eventName: String = '', async: Boolean = false){
		if(arguments.length < 2) throw new Error('Cannot make a call without the proper data'); //data is optional
		if(multipleCalls === false){
			call(api, method, data, eventName);
			return false;
		}

		let newCore = new APICoreV2(api, method, data);
		newCore.eventName = eventName;
		newCore.csrfToken = csrfToken;
		newCore.handleErrors = handleErrors;
		return newCore.call(async);

	}


	return {
		core,
		subscribeToEvents,
		call,
		setOptions,
		getOptions,
		resetAPI,
		request,
		setMultipleCalls,
		setHandleErrors,
		handle
	};
})(APICore);

module.exports = API;
