"use strict";

class WebSocketClient{
  constructor(url, params = {}){
    if (url === undefined || url === null || url === ''){
      return;
    }
    this.params = params;
    this.actions = new WebsocketActions(url, params);
    this.init();
  }
  
  init(){
    this.initButtons();
  }
  
  initButtons(){
    if (!this.params.buttons){
      return;
    }
    document.addEventListener('click',function(event){
      let buttons = this.params.buttons;
      let target = event.target;
      if (target.id === undefined || target.id === null){
        return;
      }
      let btn_action = '';
      for (let name in buttons){
        if (buttons[name].replace('#', '') == target.id){
          btn_action = name;
          break;
        }
      }
      if (btn_action){
        this.doAction(btn_action);
      }
    }.bind(this));
  }

  doAction(action){
    if (action && typeof this.actions[action] === 'function'){
      if (action === 'sendMessage' && this.params.message.element !== undefined){
        let el_message = document.querySelector(this.params.message.element);
        let message = el_message.value;
        this.actions[action](message);
        el_message.value = '';
      }else{
        this.actions[action]();
      }
    }
    else{
      console.log('unkown action');
    }
  }
}

class WebsocketActions{
  constructor(url ,params = {}){
    this.url = url;
    this.params = params;
  }

  connect(){
    if (this.connection == null){
      this.connection = new WebSocket(this.url);
      let websocketEvents = new WebsocketEvents(this.connection, this.params);
      websocketEvents.init();
    } 
  }

  disconnect(){
    this.connection.close();
  }

  sendMessage(message){
    if (message === undefined || message === ''){
      return false;
    }
    this.connection.send(message);
  }

  close(){
    this.connection.close();
  }
}

class WebsocketEvents{
  constructor(connection, params){
    this.connection = connection;
    this.params = params;
  }

  init(){
    this.onOpen();
    this.onMessage();
    this.onError();
    this.onClose();
  }

  onOpen(){
    this.connection.onopen = function(event){
      console.log(event);
    }
  }

  onMessage(){
    this.connection.onmessage = function(event){
      console.log(event);
      if (this.params.message_box.element && event.data){
        let message_box = document.querySelector(this.params.message_box.element);
        let new_message = document.createElement('div');
        new_message.textContent = event.data;
        message_box.appendChild(new_message);
      }
    }.bind(this);
  }

  onError(){
    this.connection.onerror = function(event){
      console.log(event);
    }
  }

  onClose(){
    this.connection.onclose = function(event){
      console.log(event);
    }
  }

}

