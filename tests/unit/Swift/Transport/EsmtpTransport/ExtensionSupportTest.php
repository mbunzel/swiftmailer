<?php

require_once 'Swift/Transport/EsmtpTransportTest.php';
require_once 'Swift/Transport/EsmtpTransport.php';
require_once 'Swift/Transport/EsmtpHandler.php';
require_once 'Swift/Events/EventDispatcher.php';

interface Swift_Transport_EsmtpHandlerMixin extends Swift_Transport_EsmtpHandler {
  public function setUsername($user);
  public function setPassword($pass);
}

class Swift_Transport_EsmtpTransport_ExtensionSupportTest
  extends Swift_Transport_EsmtpTransportTest
{
  
  public function testExtensionHandlersAreSortedAsNeeded()
  {
    $context = new Mockery();
    $buf = $this->_getBuffer($context);
    $smtp = $this->_getTransport($buf);
    $ext1 = $context->mock('Swift_Transport_EsmtpHandler');
    $ext2 = $context->mock('Swift_Transport_EsmtpHandler');
    $context->checking(Expectations::create()
      -> allowing($ext1)->getHandledKeyword() -> returns('AUTH')
      -> allowing($ext1)->getPriorityOver('STARTTLS') -> returns(0)
      -> allowing($ext2)->getHandledKeyword() -> returns('STARTTLS')
      -> allowing($ext2)->getPriorityOver('AUTH') -> returns(-1)
      -> ignoring($ext1)
      -> ignoring($ext2)
      );
    $this->_finishBuffer($context, $buf);
    $smtp->setExtensionHandlers(array($ext1, $ext2));
    $this->assertEqual(array($ext2, $ext1), $smtp->getExtensionHandlers());
    $context->assertIsSatisfied();
  }
  
  public function testHandlersAreNotifiedOfParams()
  {
    $context = new Mockery();
    $buf = $this->_getBuffer($context);
    $smtp = $this->_getTransport($buf);
    $ext1 = $context->mock('Swift_Transport_EsmtpHandler');
    $ext2 = $context->mock('Swift_Transport_EsmtpHandler');
    $s = $context->sequence('Initiation-sequence');
    $context->checking(Expectations::create()
      -> one($buf)->readLine(0) -> inSequence($s) -> returns("220 server.com foo\r\n")
      -> one($buf)->write(pattern('~^EHLO .*?\r\n$~D')) -> inSequence($s) -> returns(1)
      -> one($buf)->readLine(1) -> inSequence($s) -> returns("250-ServerName.tld\r\n")
      -> one($buf)->readLine(1) -> inSequence($s) -> returns("250-AUTH PLAIN LOGIN\r\n")
      -> one($buf)->readLine(1) -> inSequence($s) -> returns("250 SIZE=123456\r\n")
      
      -> allowing($ext1)->getHandledKeyword() -> returns('AUTH')
      -> one($ext1)->setKeywordParams(array('PLAIN', 'LOGIN'))
      -> allowing($ext2)->getHandledKeyword() -> returns('SIZE')
      -> allowing($ext2)->setKeywordParams(array('123456'))
      -> ignoring($ext1)
      -> ignoring($ext2)
      );
    $this->_finishBuffer($context, $buf);
    $smtp->setExtensionHandlers(array($ext1, $ext2));
    $smtp->start();
    $context->assertIsSatisfied();
  }
  
  public function testSupportedExtensionHandlersAreRunAfterEhlo()
  {
    $context = new Mockery();
    $buf = $this->_getBuffer($context);
    $smtp = $this->_getTransport($buf);
    $ext1 = $context->mock('Swift_Transport_EsmtpHandler');
    $ext2 = $context->mock('Swift_Transport_EsmtpHandler');
    $ext3 = $context->mock('Swift_Transport_EsmtpHandler');
    $s = $context->sequence('Initiation-sequence');
    $context->checking(Expectations::create()
      -> one($buf)->readLine(0) -> inSequence($s) -> returns("220 server.com foo\r\n")
      -> one($buf)->write(pattern('~^EHLO .*?\r\n$~D')) -> inSequence($s) -> returns(1)
      -> one($buf)->readLine(1) -> inSequence($s) -> returns("250-ServerName.tld\r\n")
      -> one($buf)->readLine(1) -> inSequence($s) -> returns("250-AUTH PLAIN LOGIN\r\n")
      -> one($buf)->readLine(1) -> inSequence($s) -> returns("250 SIZE=123456\r\n")
      
      -> allowing($ext1)->getHandledKeyword() -> returns('AUTH')
      -> one($ext1)->afterEhlo($smtp)
      -> allowing($ext2)->getHandledKeyword() -> returns('SIZE')
      -> allowing($ext2)->afterEhlo($smtp)
      -> allowing($ext3)->getHandledKeyword() -> returns('STARTTLS')
      -> never($ext3)->afterEhlo(any())
      -> ignoring($ext1)
      -> ignoring($ext2)
      -> ignoring($ext3)
      );
    $this->_finishBuffer($context, $buf);
    $smtp->setExtensionHandlers(array($ext1, $ext2, $ext3));
    $smtp->start();
    $context->assertIsSatisfied();
  }
  
  public function testExtensionsCanModifyMailFromParams()
  {
    $context = new Mockery();
    $buf = $this->_getBuffer($context);
    $smtp = $this->_getTransport($buf);
    $ext1 = $context->mock('Swift_Transport_EsmtpHandler');
    $ext2 = $context->mock('Swift_Transport_EsmtpHandler');
    $ext3 = $context->mock('Swift_Transport_EsmtpHandler');
    $message = $context->mock('Swift_Mime_Message');
    $s = $context->sequence('Initiation-sequence');
    $context->checking(Expectations::create()
      -> allowing($message)->getFrom() -> returns(array('me@domain'=>'Me'))
      -> allowing($message)->getTo() -> returns(array('foo@bar'=>null))
      -> ignoring($message)
      
      -> one($buf)->readLine(0) -> inSequence($s) -> returns("220 server.com foo\r\n")
      -> one($buf)->write(pattern('~^EHLO .*?\r\n$~D')) -> inSequence($s) -> returns(1)
      -> one($buf)->readLine(1) -> inSequence($s) -> returns("250-ServerName.tld\r\n")
      -> one($buf)->readLine(1) -> inSequence($s) -> returns("250-AUTH PLAIN LOGIN\r\n")
      -> one($buf)->readLine(1) -> inSequence($s) -> returns("250 SIZE=123456\r\n")
      -> one($buf)->write("MAIL FROM: <me@domain> FOO ZIP\r\n") -> inSequence($s) -> returns(2)
      -> one($buf)->readLine(2) -> inSequence($s) -> returns("250 OK\r\n")
      -> one($buf)->write("RCPT TO: <foo@bar>\r\n") -> inSequence($s) -> returns(3)
      -> one($buf)->readLine(3) -> inSequence($s) -> returns("250 OK\r\n")
      
      -> allowing($ext1)->getHandledKeyword() -> returns('AUTH')
      -> one($ext1)->getMailParams() -> returns('FOO')
      -> allowing($ext1)->getPriorityOver('AUTH') -> returns(-1)
      -> allowing($ext2)->getHandledKeyword() -> returns('SIZE')
      -> one($ext2)->getMailParams() -> returns('ZIP')
      -> allowing($ext2)->getPriorityOver('AUTH') -> returns(1)
      -> allowing($ext3)->getHandledKeyword() -> returns('STARTTLS')
      -> never($ext3)->getMailParams()
      -> ignoring($ext1)
      -> ignoring($ext2)
      -> ignoring($ext3)
      );
    $this->_finishBuffer($context, $buf);
    $smtp->setExtensionHandlers(array($ext1, $ext2, $ext3));
    $smtp->start();
    $smtp->send($message);
    $context->assertIsSatisfied();
  }
  
  public function testExtensionsCanModifyRcptParams()
  {
    $context = new Mockery();
    $buf = $this->_getBuffer($context);
    $smtp = $this->_getTransport($buf);
    $ext1 = $context->mock('Swift_Transport_EsmtpHandler');
    $ext2 = $context->mock('Swift_Transport_EsmtpHandler');
    $ext3 = $context->mock('Swift_Transport_EsmtpHandler');
    $message = $context->mock('Swift_Mime_Message');
    $s = $context->sequence('Initiation-sequence');
    $context->checking(Expectations::create()
      -> allowing($message)->getFrom() -> returns(array('me@domain'=>'Me'))
      -> allowing($message)->getTo() -> returns(array('foo@bar'=>null))
      -> ignoring($message)
      
      -> one($buf)->readLine(0) -> inSequence($s) -> returns("220 server.com foo\r\n")
      -> one($buf)->write(pattern('~^EHLO .*?\r\n$~D')) -> inSequence($s) -> returns(1)
      -> one($buf)->readLine(1) -> inSequence($s) -> returns("250-ServerName.tld\r\n")
      -> one($buf)->readLine(1) -> inSequence($s) -> returns("250-AUTH PLAIN LOGIN\r\n")
      -> one($buf)->readLine(1) -> inSequence($s) -> returns("250 SIZE=123456\r\n")
      -> one($buf)->write("MAIL FROM: <me@domain>\r\n") -> inSequence($s) -> returns(2)
      -> one($buf)->readLine(2) -> inSequence($s) -> returns("250 OK\r\n")
      -> one($buf)->write("RCPT TO: <foo@bar> FOO ZIP\r\n") -> inSequence($s) -> returns(3)
      -> one($buf)->readLine(3) -> inSequence($s) -> returns("250 OK\r\n")
      
      -> allowing($ext1)->getHandledKeyword() -> returns('AUTH')
      -> one($ext1)->getRcptParams() -> returns('FOO')
      -> allowing($ext1)->getPriorityOver('AUTH') -> returns(-1)
      -> allowing($ext2)->getHandledKeyword() -> returns('SIZE')
      -> one($ext2)->getRcptParams() -> returns('ZIP')
      -> allowing($ext2)->getPriorityOver('AUTH') -> returns(1)
      -> allowing($ext3)->getHandledKeyword() -> returns('STARTTLS')
      -> never($ext3)->getRcptParams()
      -> ignoring($ext1)
      -> ignoring($ext2)
      -> ignoring($ext3)
      );
    $this->_finishBuffer($context, $buf);
    $smtp->setExtensionHandlers(array($ext1, $ext2, $ext3));
    $smtp->start();
    $smtp->send($message);
    $context->assertIsSatisfied();
  }
  
  public function testExtensionsAreNotifiedOnCommand()
  {
    $context = new Mockery();
    $buf = $this->_getBuffer($context);
    $smtp = $this->_getTransport($buf);
    $ext1 = $context->mock('Swift_Transport_EsmtpHandler');
    $ext2 = $context->mock('Swift_Transport_EsmtpHandler');
    $ext3 = $context->mock('Swift_Transport_EsmtpHandler');
    $s = $context->sequence('Initiation-sequence');
    $context->checking(Expectations::create()
      -> one($buf)->readLine(0) -> inSequence($s) -> returns("220 server.com foo\r\n")
      -> one($buf)->write(pattern('~^EHLO .*?\r\n$~D')) -> inSequence($s) -> returns(1)
      -> one($buf)->readLine(1) -> inSequence($s) -> returns("250-ServerName.tld\r\n")
      -> one($buf)->readLine(1) -> inSequence($s) -> returns("250-AUTH PLAIN LOGIN\r\n")
      -> one($buf)->readLine(1) -> inSequence($s) -> returns("250 SIZE=123456\r\n")
      -> one($buf)->write("FOO\r\n") -> inSequence($s) -> returns(2)
      -> one($buf)->readLine(2) -> inSequence($s) -> returns("250 Cool\r\n")
      
      -> allowing($ext1)->getHandledKeyword() -> returns('AUTH')
      -> one($ext1)->onCommand($smtp, "FOO\r\n", array(250, 251), optional())
      -> allowing($ext2)->getHandledKeyword() -> returns('SIZE')
      -> one($ext2)->onCommand($smtp, "FOO\r\n", array(250, 251), optional())
      -> allowing($ext3)->getHandledKeyword() -> returns('STARTTLS')
      -> never($ext3)->onCommand(any(), any(), any(), optional())
      -> ignoring($ext1)
      -> ignoring($ext2)
      -> ignoring($ext3)
      );
    $this->_finishBuffer($context, $buf);
    $smtp->setExtensionHandlers(array($ext1, $ext2, $ext3));
    $smtp->start();
    $smtp->executeCommand("FOO\r\n", array(250, 251));
    $context->assertIsSatisfied();
  }
  
  public function testChainOfCommandAlgorithmWhenNotifyingExtensions()
  {
    $e = new Swift_Transport_CommandSentException("250 OK\r\n");
    
    $context = new Mockery();
    $buf = $this->_getBuffer($context);
    $smtp = $this->_getTransport($buf);
    $ext1 = $context->mock('Swift_Transport_EsmtpHandler');
    $ext2 = $context->mock('Swift_Transport_EsmtpHandler');
    $ext3 = $context->mock('Swift_Transport_EsmtpHandler');
    $s = $context->sequence('Initiation-sequence');
    $context->checking(Expectations::create()
      -> one($buf)->readLine(0) -> inSequence($s) -> returns("220 server.com foo\r\n")
      -> one($buf)->write(pattern('~^EHLO .*?\r\n$~D')) -> inSequence($s) -> returns(1)
      -> one($buf)->readLine(1) -> inSequence($s) -> returns("250-ServerName.tld\r\n")
      -> one($buf)->readLine(1) -> inSequence($s) -> returns("250-AUTH PLAIN LOGIN\r\n")
      -> one($buf)->readLine(1) -> inSequence($s) -> returns("250 SIZE=123456\r\n")
      -> never($buf)->write("FOO\r\n")
      
      -> allowing($ext1)->getHandledKeyword() -> returns('AUTH')
      -> one($ext1)->onCommand($smtp, "FOO\r\n", array(250, 251), optional()) -> throws($e)
      -> allowing($ext2)->getHandledKeyword() -> returns('SIZE')
      -> never($ext2)->onCommand(any(), any(), any(), optional())
      -> allowing($ext3)->getHandledKeyword() -> returns('STARTTLS')
      -> never($ext3)->onCommand(any(), any(), any(), optional())
      -> ignoring($ext1)
      -> ignoring($ext2)
      -> ignoring($ext3)
      );
    $this->_finishBuffer($context, $buf);
    $smtp->setExtensionHandlers(array($ext1, $ext2, $ext3));
    $smtp->start();
    $smtp->executeCommand("FOO\r\n", array(250, 251));
    $context->assertIsSatisfied();
  }
  
  public function testExtensionsCanExposeMixinMethods()
  {
    $context = new Mockery();
    $buf = $this->_getBuffer($context);
    $smtp = $this->_getTransport($buf);
    $ext1 = $context->mock('Swift_Transport_EsmtpHandlerMixin');
    $ext2 = $context->mock('Swift_Transport_EsmtpHandler');
    $context->checking(Expectations::create()
      -> allowing($ext1)->getHandledKeyword() -> returns('AUTH')
      -> allowing($ext1)->exposeMixinMethods() -> returns(array('setUsername', 'setPassword'))
      -> one($ext1)->setUsername('mick')
      -> one($ext1)->setPassword('pass')
      -> allowing($ext2)->getHandledKeyword() -> returns('STARTTLS')
      -> ignoring($ext1)
      -> ignoring($ext2)
      );
    $this->_finishBuffer($context, $buf);
    $smtp->setExtensionHandlers(array($ext1, $ext2));
    $smtp->setUsername('mick');
    $smtp->setPassword('pass');
    $context->assertIsSatisfied();
  }
  
  public function testMixinMethodsBeginningWithSetAndNullReturnAreFluid()
  {
    $context = new Mockery();
    $buf = $this->_getBuffer($context);
    $smtp = $this->_getTransport($buf);
    $ext1 = $context->mock('Swift_Transport_EsmtpHandlerMixin');
    $ext2 = $context->mock('Swift_Transport_EsmtpHandler');
    $context->checking(Expectations::create()
      -> allowing($ext1)->getHandledKeyword() -> returns('AUTH')
      -> allowing($ext1)->exposeMixinMethods() -> returns(array('setUsername', 'setPassword'))
      -> one($ext1)->setUsername('mick') -> returns(NULL)
      -> one($ext1)->setPassword('pass') -> returns(NULL)
      -> allowing($ext2)->getHandledKeyword() -> returns('STARTTLS')
      -> ignoring($ext1)
      -> ignoring($ext2)
      );
    $this->_finishBuffer($context, $buf);
    $smtp->setExtensionHandlers(array($ext1, $ext2));
    $this->assertReference($smtp, $smtp->setUsername('mick'));
    $this->assertReference($smtp, $smtp->setPassword('pass'));
    $context->assertIsSatisfied();
  }
  
  public function testMixinSetterWhichReturnValuesAreNotFluid()
  {
    $context = new Mockery();
    $buf = $this->_getBuffer($context);
    $smtp = $this->_getTransport($buf);
    $ext1 = $context->mock('Swift_Transport_EsmtpHandlerMixin');
    $ext2 = $context->mock('Swift_Transport_EsmtpHandler');
    $context->checking(Expectations::create()
      -> allowing($ext1)->getHandledKeyword() -> returns('AUTH')
      -> allowing($ext1)->exposeMixinMethods() -> returns(array('setUsername', 'setPassword'))
      -> one($ext1)->setUsername('mick') -> returns('x')
      -> one($ext1)->setPassword('pass') -> returns('x')
      -> allowing($ext2)->getHandledKeyword() -> returns('STARTTLS')
      -> ignoring($ext1)
      -> ignoring($ext2)
      );
    $this->_finishBuffer($context, $buf);
    $smtp->setExtensionHandlers(array($ext1, $ext2));
    $this->assertEqual('x', $smtp->setUsername('mick'));
    $this->assertEqual('x', $smtp->setPassword('pass'));
    $context->assertIsSatisfied();
  }
  
}
