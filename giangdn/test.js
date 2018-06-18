/**
 * Sample React Native App
 * https://github.com/facebook/react-native
 * @flow
 */

import React, { Component } from 'react';
import { Provider } from 'react-redux';

import configureStore from './src/store';
import AppNavigation from './src/navigation/AppNavigation';

console.disableYellowBox = true;

export default class App extends Component {
  constructor(props) {
    super(props);

    this.state = {
      isRehydrated: true,
      store: configureStore(() => this.setState({ isRehydrated: false })),
    };
  }

  render() {
    if (this.state.isRehydrated) {
      return null;
    }

    return (
      <Provider store={this.state.store}>
        <AppNavigation />
      </Provider>
    );
  }
}
