import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { AuthProvider } from './context/AuthContext';
import { ToastProvider } from './components/Toast';
import ProtectedRoute from './components/ProtectedRoute';
import Layout from './components/Layout';

import Login from './pages/Login';
import Register from './pages/Register';
import Marketplace from './pages/Marketplace';
import PostOffer from './pages/PostOffer';
import Trades from './pages/Trades';
import TradeDetail from './pages/TradeDetail';
import PaymentMethods from './pages/PaymentMethods';
import Deposit from './pages/Deposit';
import Withdraw from './pages/Withdraw';

export default function App() {
  return (
    <AuthProvider>
      <ToastProvider>
        <BrowserRouter>
          <Routes>
            {/* Auth */}
            <Route path="/login" element={<Login />} />
            <Route path="/register" element={<Register />} />

            {/* Authenticated app shell */}
            <Route
              path="/"
              element={
                <ProtectedRoute>
                  <Layout />
                </ProtectedRoute>
              }
            >
              <Route index element={<Navigate to="/marketplace" replace />} />
              <Route path="marketplace" element={<Marketplace />} />
              <Route path="trades" element={<Trades />} />
              <Route path="trades/:tradeRef" element={<TradeDetail />} />
              <Route path="post" element={<PostOffer />} />
              <Route path="payment-methods" element={<PaymentMethods />} />
              <Route path="wallet/deposit" element={<Deposit />} />
              <Route path="wallet/withdraw" element={<Withdraw />} />
            </Route>

            <Route path="*" element={<Navigate to="/marketplace" replace />} />
          </Routes>
        </BrowserRouter>
      </ToastProvider>
    </AuthProvider>
  );
}
